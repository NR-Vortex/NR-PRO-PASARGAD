<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramAdminCommandHandler;

try {
    $adminId = 123456;

    $config = new Config([
        'telegram' => [
            'admin_id' => (string) $adminId,
        ],
    ]);

    $handler = new TelegramAdminCommandHandler($config);

    $payload = [
        'update_id' => 1,
        'user_id' => $adminId,
        'chat_id' => $adminId,
        'command' => 'admin',
    ];

    $noDatabase = static function (int $owner): array {
        throw new RuntimeException('Unexpected database access.');
    };

    $reply = $handler->render($payload, $noDatabase);

    if (!str_contains($reply['text'], '/panels')) {
        throw new RuntimeException('Admin menu failed.');
    }

    echo "[OK] ADMIN MENU\n";

    $rows = [
        [
            'panel_id' => 'eu-1',
            'display_name' => 'Europe',
            'driver_type' => 'marzban',
            'is_enabled' => 't',
            'base_url' => 'https://private.example',
            'encrypted_credentials' => 'PRIVATE_SECRET',
        ],
    ];

    $list = static function (int $owner) use ($adminId, $rows): array {
        if ($owner !== $adminId) {
            throw new RuntimeException('Wrong owner.');
        }

        return $rows;
    };

    $payload['command'] = 'panels';

    $reply = $handler->render($payload, $list);

    if (!str_contains($reply['text'], 'eu-1')) {
        throw new RuntimeException('Panel list failed.');
    }

    if (
        str_contains($reply['text'], 'PRIVATE_SECRET') ||
        str_contains($reply['text'], 'private.example')
    ) {
        throw new RuntimeException('Sensitive data leaked.');
    }

    echo "[OK] PANEL LIST AND SECRET REDACTION\n";

    $payload['command'] = 'panel';
    $payload['panel_id'] = 'eu-1';

    $reply = $handler->render($payload, $list);

    if (!str_contains($reply['text'], 'eu-1')) {
        throw new RuntimeException('Panel details failed.');
    }

    echo "[OK] PANEL DETAILS\n";

    $payload['user_id'] = 999999;

    try {
        $handler->render($payload, $list);
        throw new RuntimeException('Unauthorized user accepted.');
    } catch (InvalidArgumentException) {
        echo "[OK] UNAUTHORIZED ACCESS BLOCKED\n";
    }

    echo "[SUCCESS] STAGE 21A ADMIN COMMAND TESTS PASSED\n";

} catch (Throwable $error) {
    fwrite(STDERR, "[FAILED] " . $error->getMessage() . PHP_EOL);
    exit(1);
}