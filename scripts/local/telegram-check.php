<?php

declare(strict_types=1);

use NRPro\Pasargad\Core\Container\Container;
use NRPro\Pasargad\Integrations\Telegram\Client\TelegramClient;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramUpdateParser;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$mode = $argv[1] ?? '--self-test';
if (!in_array($mode, ['--self-test', '--get-me'], true) || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php scripts/local/telegram-check.php [--self-test|--get-me]\n");
    exit(2);
}

$root = dirname(__DIR__, 2);

try {
    require $root . '/bootstrap/app.php';

    if (!isset($container) || !$container instanceof Container) {
        throw new RuntimeException('Dependency container was not initialized.');
    }

    $client = $container->get(TelegramClient::class);
    if (!$client instanceof TelegramClient) {
        throw new RuntimeException('TelegramClient registration is incorrect.');
    }

    echo "NR PRO PASARGAD TELEGRAM CHECK\n";

    if ($mode === '--self-test') {
        $parser = new TelegramUpdateParser();
        $parsed = $parser->parseCommand([
            'update_id' => 123,
            'message' => [
                'text' => '/start demo',
                'chat' => ['id' => 456],
                'from' => ['id' => 789],
            ],
        ]);

        if ($parsed === null || $parsed['command'] !== 'start' || $parsed['argument'] !== 'demo') {
            throw new RuntimeException('Telegram parser self-test failed.');
        }

        if ($parser->parseCommand(['update_id' => 124, 'message' => ['text' => 'hello']]) !== null) {
            throw new RuntimeException('Telegram parser accepted invalid update.');
        }

        echo "[OK] TELEGRAM CLIENT REGISTERED\n";
        echo "[OK] UPDATE COMMAND PARSER\n";
        echo "[SUCCESS] OFFLINE TELEGRAM SELF-TEST PASSED\n";
        exit(0);
    }

    $bot = $client->getMe();
    if (($bot['is_bot'] ?? false) !== true || !is_string($bot['username'] ?? null)) {
        throw new RuntimeException('Telegram getMe returned unexpected bot identity.');
    }

    echo '[OK] TELEGRAM API CONNECTED' . PHP_EOL;
    echo '[OK] BOT USERNAME: @' . $bot['username'] . PHP_EOL;
    echo '[SUCCESS] TELEGRAM AUTHENTICATION VERIFIED' . PHP_EOL;
    exit(0);

} catch (Throwable $error) {
    // Only emit exceptions intentionally produced by this package, never token/URL.
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
