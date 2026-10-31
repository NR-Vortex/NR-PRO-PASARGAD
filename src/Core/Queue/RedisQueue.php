<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Queue;

use InvalidArgumentException;
use JsonException;
use NRPro\Pasargad\Core\Config\Config;
use Predis\Client;
use RuntimeException;

/**
 * NR PRO PASARGAD - Queue v1.1 (developer queue).
 *
 * Uses Redis lists for pending/processing/failed and a sorted set for
 * delayed retries. This is an at-least-once system: job handlers MUST be
 * idempotent. Crashed workers require manual recovery when all workers stop.
 */
final class RedisQueue
{
    private ?Client $client = null;

    private readonly string $prefix;

    public function __construct(private readonly Config $config)
    {
        $name = (string) $config->get('queue.name', 'default');

        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)) {
            throw new InvalidArgumentException('Invalid queue name.');
        }

        $this->prefix = 'nrpro:queue:' . $name . ':';
    }

    private function redis(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $host = (string) $this->config->get('redis.host', '127.0.0.1');
        $port = (int) $this->config->get('redis.port', 6380);
        $database = (int) $this->config->get('redis.database', 0);
        $password = (string) $this->config->get('redis.password', '');
        $timeout = (float) $this->config->get('redis.timeout', 3);

        if ($host === '' || $port < 1 || $port > 65535 || $database < 0 || $timeout <= 1) {
            throw new RuntimeException('Invalid Redis queue connection configuration.');
        }

        $params = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'timeout' => $timeout,
            'read_write_timeout' => $timeout,
        ];

        if ($password !== '') {
            $params['password'] = $password;
        }

        return $this->client = new Client($params);
    }

    private function key(string $suffix): string
    {
        return $this->prefix . $suffix;
    }

    /** @param array<string, mixed> $payload */
    public function enqueue(string $type, array $payload = [], int $maxAttempts = 3): string
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $type)) {
            throw new InvalidArgumentException('Invalid job type.');
        }

        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new InvalidArgumentException('maxAttempts must be between 1 and 20.');
        }

        $id = bin2hex(random_bytes(16));
        $raw = json_encode([
            'id' => $id,
            'type' => $type,
            'payload' => $payload,
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'created_at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (strlen($raw) > 65536) {
            throw new InvalidArgumentException('Job is larger than 64 KiB.');
        }

        $this->redis()->executeRaw(['LPUSH', $this->key('pending'), $raw]);

        return $id;
    }

    /**
     * Atomically move due retries into the pending list.
     * Call before every claim; multiple workers can call concurrently.
     */
    public function promoteDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Promotion limit must be 1-1000.');
        }

        $lua = <<<'LUA'
local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, tonumber(ARGV[2]))
local count = 0
for _, payload in ipairs(due) do
    if redis.call('ZREM', KEYS[1], payload) == 1 then
        redis.call('LPUSH', KEYS[2], payload)
        count = count + 1
    end
end
return count
LUA;

        return (int) $this->redis()->executeRaw([
            'EVAL', $lua, '2', $this->key('delayed'), $this->key('pending'),
            (string) time(), (string) $limit,
        ]);
    }

    /**
     * @return array{raw:string,job:array<string,mixed>}|null
     * @throws JsonException
     */
    public function claim(int $timeoutSeconds = 1): ?array
    {
        if ($timeoutSeconds < 1 || $timeoutSeconds > 2) {
            throw new InvalidArgumentException('Claim timeout must be 1 or 2 seconds.');
        }

        $raw = $this->redis()->executeRaw([
            'BLMOVE', $this->key('pending'), $this->key('processing'),
            'RIGHT', 'LEFT', (string) $timeoutSeconds,
        ]);

        if ($raw === null) {
            return null;
        }

        $job = json_decode((string) $raw, true, 16, JSON_THROW_ON_ERROR);

        if (!is_array($job) || !isset($job['id'], $job['type'])) {
            throw new RuntimeException('Invalid job found in processing list.');
        }

        return ['raw' => (string) $raw, 'job' => $job];
    }

    public function acknowledge(string $raw): bool
    {
        return (int) $this->redis()->executeRaw([
            'LREM', $this->key('processing'), '1', $raw,
        ]) === 1;
    }

    /**
     * Atomically put an unprocessed job back on the pending list.
     * Used when live Telegram delivery was not explicitly authorized.
     */
    public function release(string $raw): bool
    {
        $lua = <<<'LUA'
if redis.call('LREM', KEYS[1], 1, ARGV[1]) ~= 1 then return 0 end
redis.call('RPUSH', KEYS[2], ARGV[1])
return 1
LUA;

        return (int) $this->redis()->executeRaw([
            'EVAL', $lua, '2', $this->key('processing'),
            $this->key('pending'), $raw,
        ]) === 1;
    }

    /**
     * On handler failure, atomically move the job to delayed or failed.
     * First retry delay = 2 seconds, then 4, 8, ... capped at 60 seconds.
     *
     * @param array<string,mixed> $job
     * @return 'delayed'|'failed'
     */
    public function reject(string $raw, array $job, string $reason, bool $permanent = false): string
    {
        $attempts = (int) ($job['attempts'] ?? 0) + 1;
        $maxAttempts = (int) ($job['max_attempts'] ?? 3);
        $failed = $permanent || $attempts >= $maxAttempts;

        $job['attempts'] = $attempts;
        $job['last_error'] = substr($reason, 0, 200);
        $job['last_error_at'] = gmdate('c');

        if (!$failed) {
            $delay = min(60, 2 ** min($attempts, 10));
            $job['retry_after'] = time() + $delay;
        }

        $newRaw = json_encode(
            $job,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (strlen($newRaw) > 65536) {
            // Never allow an oversized retry payload to be silently lost.
            throw new RuntimeException('Retry payload exceeds 64 KiB.');
        }

        $lua = <<<'LUA'
local removed = redis.call('LREM', KEYS[1], 1, ARGV[1])
if removed ~= 1 then return 0 end
if ARGV[3] == 'failed' then
    redis.call('LPUSH', KEYS[2], ARGV[2])
else
    redis.call('ZADD', KEYS[2], tonumber(ARGV[4]), ARGV[2])
end
return 1
LUA;

        $state = $failed ? 'failed' : 'delayed';
        $destination = $this->key($state);
        $score = $failed ? '0' : (string) $job['retry_after'];

        $moved = (int) $this->redis()->executeRaw([
            'EVAL', $lua, '2', $this->key('processing'), $destination,
            $raw, $newRaw, $state, $score,
        ]);

        if ($moved !== 1) {
            throw new RuntimeException('Failed to move claimed job; inspect processing list.');
        }

        return $state;
    }

    /** @return array{pending:int,processing:int,delayed:int,failed:int} */
    public function stats(): array
    {
        $stats = [];

        foreach (['pending', 'processing', 'failed'] as $state) {
            $stats[$state] = (int) $this->redis()->executeRaw([
                'LLEN', $this->key($state),
            ]);
        }

        $stats['delayed'] = (int) $this->redis()->executeRaw([
            'ZCARD', $this->key('delayed'),
        ]);

        return $stats;
    }

    /**
     * Returns safe metadata only: never print a stored job payload.
     * @return list<array{id:string,type:string,attempts:int,error:string}>
     */
    public function failedJobs(int $limit = 10): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Failed-job limit must be 1-100.');
        }

        $rawItems = $this->redis()->executeRaw([
            'LRANGE', $this->key('failed'), '0', (string) ($limit - 1),
        ]);

        $result = [];

        foreach ($rawItems as $raw) {
            try {
                $job = json_decode((string) $raw, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $job = [];
            }

            $result[] = [
                'id' => is_string($job['id'] ?? null) ? $job['id'] : 'unknown',
                'type' => is_string($job['type'] ?? null) ? $job['type'] : 'unknown',
                'attempts' => (int) ($job['attempts'] ?? 0),
                'error' => is_string($job['last_error'] ?? null)
                    ? str_replace(["\r", "\n"], ' ', substr($job['last_error'], 0, 100))
                    : 'unknown',
            ];
        }

        return $result;
    }

    /**
     * WARNING: execute only when ALL queue workers have stopped.
     * Jobs may have already performed side effects before a worker crashed.
     */
    public function recover(): int
    {
        $count = 0;

        while ($this->redis()->executeRaw([
            'LMOVE', $this->key('processing'), $this->key('pending'),
            'RIGHT', 'LEFT',
        ]) !== null) {
            $count++;
        }

        return $count;
    }

    public function disconnect(): void
    {
        if ($this->client !== null) {
            $this->client->disconnect();
            $this->client = null;
        }
    }
}
