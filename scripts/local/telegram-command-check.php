<?php

declare(strict_types=1);

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Container\Container;
use NRPro\Pasargad\Core\Queue\RedisQueue;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramCommandHandler;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$mode = $argv[1] ?? '--self-test';
if (!in_array($mode, ['--self-test', '--redis-test'], true)) {
    fwrite(STDERR, "Usage: telegram-command-check.php [--self-test|--redis-test]\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

try {
    $handler = new TelegramCommandHandler();
    $base = ['update_id' => 100001, 'chat_id' => 987654321, 'user_id' => 987654321];

    foreach (['start', 'help'] as $command) {
        $reply = $handler->render($base + ['command' => $command]);
        if ($reply['chat_id'] !== $base['chat_id'] || !is_string($reply['text'])
            || $reply['text'] === '' || strlen($reply['text']) > 4096) {
            throw new RuntimeException('Invalid reply for ' . $command);
        }
        echo '[OK] /' . $command . ' RENDERED' . PHP_EOL;
    }

    try {
        $handler->render($base + ['command' => 'delete']);
        throw new RuntimeException('Unsupported command was accepted.');
    } catch (InvalidArgumentException) {
        echo '[OK] UNSUPPORTED COMMAND BLOCKED' . PHP_EOL;
    }

    if ($mode === '--self-test') {
        echo '[SUCCESS] OFFLINE COMMAND SELF-TEST PASSED; NO MESSAGES SENT' . PHP_EOL;
        exit(0);
    }

    // Isolated queue name: never touch normal jobs or production queue keys.
    require $root . '/bootstrap/app.php';
    if (!isset($container) || !$container instanceof Container) {
        throw new RuntimeException('Container unavailable.');
    }
    /** @var Config $config */
    $config = $container->get(Config::class);
    $testConfig = new Config([
        'redis' => [
            'host' => $config->get('redis.host', '127.0.0.1'),
            'port' => $config->get('redis.port', '6380'),
            'database' => $config->get('redis.database', '0'),
            'password' => $config->get('redis.password', ''),
            'timeout' => $config->get('redis.timeout', '3'),
        ],
        'queue' => ['name' => 'stage13test_' . bin2hex(random_bytes(8))],
    ]);
    $queue = new RedisQueue($testConfig);
    try {
        $id = $queue->enqueue('telegram.command', $base + ['command' => 'start']);
        $claimed = $queue->claim(1);
        if ($claimed === null || ($claimed['job']['id'] ?? '') !== $id) {
            throw new RuntimeException('Cannot claim isolated Telegram job.');
        }
        $reply = $handler->render($claimed['job']['payload']);
        if ($reply['chat_id'] !== $base['chat_id']) {
            throw new RuntimeException('Claimed Telegram job did not render.');
        }
        if (!$queue->acknowledge($claimed['raw'])) {
            throw new RuntimeException('Isolated Telegram job acknowledgment failed.');
        }
        $stats = $queue->stats();
        if (array_sum($stats) !== 0) {
            throw new RuntimeException('Isolated queue should be empty after acknowledgment.');
        }
    } finally {
        $queue->disconnect();
    }
    echo '[OK] REDIS JOB HANDLED AND ACKNOWLEDGED (ISOLATED)' . PHP_EOL;
    echo '[SUCCESS] COMMAND REDIS TEST PASSED; NO MESSAGES SENT' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
