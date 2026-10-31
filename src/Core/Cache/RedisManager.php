<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Cache;

use InvalidArgumentException;
use NRPro\Pasargad\Core\Config\Config;
use Predis\Client;
use RuntimeException;

final class RedisManager
{
    private ?Client $client = null;

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Get Redis client.
     */
    private function client(): Client
    {
        if ($this->client instanceof Client) {
            return $this->client;
        }

        $host = (string) $this->config->get(
            'redis.host',
            '127.0.0.1'
        );

        $port = (int) $this->config->get(
            'redis.port',
            6380
        );

        $database = (int) $this->config->get(
            'redis.database',
            0
        );

        $password = (string) $this->config->get(
            'redis.password',
            ''
        );

        $timeout = (float) $this->config->get(
            'redis.timeout',
            3
        );

        if (
            $host === '' ||
            $port < 1 ||
            $port > 65535 ||
            $database < 0 ||
            $timeout <= 0
        ) {
            throw new RuntimeException(
                'Invalid Redis configuration.'
            );
        }

        $parameters = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'timeout' => $timeout,
            'read_write_timeout' => $timeout,
        ];

        if ($password !== '') {
            $parameters['password'] = $password;
        }

        $this->client = new Client($parameters);

        return $this->client;
    }

    /**
     * Get value from Redis.
     */
    public function get(string $key): ?string
    {
        $value = $this->client()->get($key);

        return $value === null
            ? null
            : (string) $value;
    }

    /**
     * Store a string value.
     */
    public function set(
        string $key,
        string $value,
        ?int $ttl = null
    ): void {
        if ($ttl !== null && $ttl < 1) {
            throw new InvalidArgumentException(
                'Redis TTL must be positive.'
            );
        }

        if ($ttl !== null) {
            $this->client()->setex(
                $key,
                $ttl,
                $value
            );

            return;
        }

        $this->client()->set(
            $key,
            $value
        );
    }

    /**
     * Delete a key.
     */
    public function delete(string $key): bool
    {
        return (int) $this->client()->del(
            [$key]
        ) > 0;
    }

    /**
     * Check whether a key exists.
     */
    public function exists(string $key): bool
    {
        return (int) $this->client()->exists(
            $key
        ) > 0;
    }

    /**
     * Increment numeric value.
     */
    public function increment(
        string $key,
        int $amount = 1
    ): int {
        if ($amount < 1) {
            throw new InvalidArgumentException(
                'Increment amount must be positive.'
            );
        }

        return (int) $this->client()->incrby(
            $key,
            $amount
        );
    }

    /**
     * Set expiration time.
     */
    public function expire(
        string $key,
        int $seconds
    ): bool {
        if ($seconds < 1) {
            throw new InvalidArgumentException(
                'Expiration must be positive.'
            );
        }

        return (int) $this->client()->expire(
            $key,
            $seconds
        ) === 1;
    }

    /**
     * Check Redis connection.
     */
    public function ping(): bool
    {
        return strtoupper(
            (string) $this->client()->ping()
        ) === 'PONG';
    }

    /**
     * Disconnect Redis client.
     */
    public function disconnect(): void
    {
        if ($this->client instanceof Client) {
            $this->client->disconnect();
        }

        $this->client = null;
    }
}