<?php

declare(strict_types=1);

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramUpdateParser;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramWebhookInbox;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramWebhookReceiver;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramWebhookStore;
use Predis\Client;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$mode = $argv[1] ?? '--self-test';
if (!in_array($mode, ['--self-test', '--redis-test'], true) || count($argv) > 2) {
    fwrite(STDERR, "Usage: php scripts/local/telegram-webhook-check.php [--self-test|--redis-test]\n");
    exit(2);
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

function assertWebhook(bool $pass, string $label): void
{
    if (!$pass) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    echo '[OK] ' . $label . PHP_EOL;
}

function sampleUpdate(int $updateId, string $command = 'start'): string
{
    return json_encode([
        'update_id' => $updateId,
        'message' => [
            'message_id' => 1,
            'text' => '/' . $command,
            'chat' => ['id' => 112233],
            'from' => ['id' => 112233],
        ],
    ], JSON_THROW_ON_ERROR);
}

try {
    $secret = str_repeat('A', 48);
    $config = new Config(['telegram' => ['webhook_secret' => $secret]]);
    $fake = new class implements TelegramWebhookStore {
        /** @var array<int,true> */
        public array $seen = [];
        /** @var list<array<string,int|string>> */
        public array $jobs = [];
        public function enqueueOnce(int $updateId, array $payload): bool
        {
            if (isset($this->seen[$updateId])) {
                return false;
            }
            $this->seen[$updateId] = true;
            $this->jobs[] = $payload;
            return true;
        }
    };
    $receiver = new TelegramWebhookReceiver($config, new TelegramUpdateParser(), $fake);

    assertWebhook($receiver->receive('GET', '', '', '')['status'] === 405, 'METHOD BLOCKED');
    assertWebhook($receiver->receive('POST', 'application/json', 'bad', sampleUpdate(1))['status'] === 403, 'INVALID SECRET BLOCKED');
    assertWebhook($receiver->receive('POST', 'application/json', $secret, '{') ['status'] === 400, 'INVALID JSON BLOCKED');
    assertWebhook($receiver->receive('POST', 'application/json', $secret, sampleUpdate(1))['code'] === 'queued', 'START QUEUED');
    assertWebhook($receiver->receive('POST', 'application/json', $secret, sampleUpdate(1))['code'] === 'duplicate', 'DUPLICATE BLOCKED');
    assertWebhook($receiver->receive('POST', 'application/json', $secret, sampleUpdate(2, 'help'))['code'] === 'queued', 'HELP QUEUED');
    assertWebhook($receiver->receive('POST', 'application/json', $secret, sampleUpdate(3, 'unknown'))['code'] === 'ignored', 'UNSUPPORTED COMMAND IGNORED');
    assertWebhook(count($fake->jobs) === 2, 'EXACTLY TWO JOBS');

    if ($mode === '--self-test') {
        echo '[SUCCESS] OFFLINE WEBHOOK TEST PASSED; NO TELEGRAM MESSAGES SENT' . PHP_EOL;
        exit(0);
    }

    // Redis integration uses a fresh ISOLATED queue name; never touches real jobs.
    $root = dirname(__DIR__, 2);
    $env = new Environment($root . '/.env');
    $env->load();
    $queueName = 'stage12test' . bin2hex(random_bytes(5));
    $host = (string) $env->get('REDIS_HOST', '127.0.0.1');
    $port = (int) $env->get('REDIS_PORT', '6380');
    $db = (int) $env->get('REDIS_DATABASE', '0');
    $password = (string) $env->get('REDIS_PASSWORD', '');
    $timeout = (float) $env->get('REDIS_TIMEOUT', '3');
    $settings = [
        'scheme' => 'tcp', 'host' => $host, 'port' => $port,
        'database' => $db, 'timeout' => $timeout,
        'read_write_timeout' => $timeout,
    ];
    if ($password !== '') {
        $settings['password'] = $password;
    }
    $redis = new Client($settings);
    $integrationConfig = new Config([
        'telegram' => ['webhook_secret' => $secret],
        'redis' => [
            'host' => $host, 'port' => $port, 'database' => $db,
            'password' => $password, 'timeout' => $timeout,
        ],
        'queue' => ['name' => $queueName],
    ]);
    $real = new TelegramWebhookReceiver(
        $integrationConfig, new TelegramUpdateParser(),
        new TelegramWebhookInbox($integrationConfig)
    );
    $updateId = random_int(500000000, 1500000000);
    $queueKey = 'nrpro:queue:' . $queueName . ':pending';
    $dedupKey = 'nrpro:telegram:updates:' . $queueName . ':' . $updateId;

    try {
        assertWebhook($real->receive('POST', 'application/json', $secret, sampleUpdate($updateId))['code'] === 'queued', 'REDIS ENQUEUE');
        assertWebhook($real->receive('POST', 'application/json', $secret, sampleUpdate($updateId))['code'] === 'duplicate', 'REDIS DEDUPLICATION');
        assertWebhook((int) $redis->llen($queueKey) === 1, 'EXACTLY ONE REDIS JOB');
    } finally {
        // Only keys with this freshly generated test queue prefix are deleted.
        $redis->del([$queueKey, $dedupKey]);
        $redis->disconnect();
    }

    echo '[SUCCESS] REDIS WEBHOOK TEST PASSED; NO TELEGRAM MESSAGES SENT' . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] WEBHOOK TEST FAILED: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
