<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramAdminCallbackHandler;
use NRPro\Pasargad\Integrations\Telegram\Keyboards\AdminKeyboard;

try {

    $handler = new TelegramAdminCallbackHandler(
        new Config([
            'telegram' => [
                'admin_id' => '123456',
            ],
        ])
    );

    $payload = [
        'update_id' => 10,
        'chat_id' => 123456,
        'user_id' => 123456,
        'command' => 'admin_callback',
        'action' => 'home',
        'callback_id' => 'callback-10',
        'message_id' => 99,
    ];

    // Fake database metadata. No real connection.
    $rows = [
        [
            'panel_id' => 'eu-1',
            'display_name' => "EU\nSECRET_NAME",
            'driver_type' => 'marzban',
            'is_enabled' => 't',
            'base_url' => 'https://hidden.example',
            'encrypted_credentials' => 'SECRET_CIPHERTEXT',
        ],
    ];

    $dbReads = 0;

    $list = static function (
        int $owner
    ) use ($rows, &$dbReads): array {

        if ($owner !== 123456) {
            throw new RuntimeException(
                'Owner mismatch.'
            );
        }

        ++$dbReads;

        return $rows;
    };

    /*
     * DASHBOARD
     */
    $menu = $handler->render(
        $payload,
        $list
    );

    if (
        $menu['reply_markup']['inline_keyboard'][0][0]['callback_data']
            !== 'nr:a:list'
        || $dbReads !== 0
    ) {
        throw new RuntimeException(
            'Dashboard failed.'
        );
    }

    echo "[OK] DASHBOARD\n";

    /*
     * PANEL LIST
     */
    $payload['action'] = 'list';

    $reply = $handler->render(
        $payload,
        $list
    );

    if (
        $dbReads !== 1 ||
        !str_contains($reply['text'], 'پنل')
    ) {
        throw new RuntimeException(
            'Panel list failed.'
        );
    }

    echo "[OK] PANEL LIST\n";

    /*
     * PANEL STATS
     */
    $payload['action'] = 'stats';

    $reply = $handler->render(
        $payload,
        $list
    );

    if (!str_contains(
        $reply['text'],
        'فعال: 1'
    )) {
        throw new RuntimeException(
            'Stats failed.'
        );
    }

    echo "[OK] PANEL STATS\n";

    /*
     * PANEL DETAILS
     */
    $payload['action'] = 'panel';

    $payload['panel_key'] =
        AdminKeyboard::panelKey('eu-1');

    $reply = $handler->render(
        $payload,
        $list
    );

    if (
        !str_contains($reply['text'], 'eu-1')
        || str_contains(
            $reply['text'],
            "EU\nSECRET_NAME"
        )
        || str_contains(
            $reply['text'],
            'SECRET_CIPHERTEXT'
        )
        || str_contains(
            $reply['text'],
            'hidden.example'
        )
    ) {
        throw new RuntimeException(
            'Unsafe panel details.'
        );
    }

    echo "[OK] PANEL DETAILS / SECRETS HIDDEN\n";

    /*
     * UNKNOWN PANEL
     */
    $payload['panel_key'] = str_repeat(
        'a',
        20
    );

    $reply = $handler->render(
        $payload,
        $list
    );

    if (!str_contains(
        $reply['text'],
        'پیدا نشد'
    )) {
        throw new RuntimeException(
            'Unknown panel accepted.'
        );
    }

    echo "[OK] UNKNOWN PANEL DENIED\n";

    /*
     * UNAUTHORIZED USER
     */
    $payload['user_id'] = 999999;

    try {

        $handler->render(
            $payload,
            $list
        );

        throw new RuntimeException(
            'Unauthorized user accepted.'
        );

    } catch (InvalidArgumentException) {

        echo "[OK] ADMIN ACCESS VERIFIED\n";
    }

    echo "\n";
    echo "[SUCCESS] STAGE 21B CALLBACK HANDLER OFFLINE TESTS PASSED";
    echo "\n";

} catch (Throwable $error) {

    fwrite(
        STDERR,
        '[FAILED] ' . $error->getMessage() . PHP_EOL
    );

    exit(1);
}