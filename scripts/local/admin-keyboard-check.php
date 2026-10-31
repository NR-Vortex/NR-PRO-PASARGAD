<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__, 2)
    . '/src/Integrations/Telegram/Keyboards/AdminKeyboard.php';

use NRPro\Pasargad\Integrations\Telegram\Keyboards\AdminKeyboard;

try {

    echo PHP_EOL;
    echo "=== NR PRO BOT ADMIN KEYBOARD TEST ===";
    echo PHP_EOL;

    // Test main dashboard

    $dashboard = AdminKeyboard::dashboard();

    $rows = $dashboard['inline_keyboard'] ?? [];

    if (
        count($rows) !== 2 ||
        $rows[0][0]['callback_data'] !== 'nr:a:list'
    ) {
        throw new RuntimeException(
            'Dashboard keyboard invalid.'
        );
    }

    echo "[OK] DASHBOARD KEYBOARD";
    echo PHP_EOL;

    // Test panel list

    $panels = [
        [
            'panel_id' => 'eu-1',
            'display_name' => 'سرور اروپا',
            'is_enabled' => 't',
        ],
        [
            'panel_id' => 'us-2',
            'display_name' => 'Server US',
            'is_enabled' => false,
        ],
    ];

    $list = AdminKeyboard::panelList($panels);

    $first = $list['inline_keyboard'][0][0];

    $second = $list['inline_keyboard'][1][0];

    if (
        !str_starts_with($first['text'], '🟢 ') ||
        !str_starts_with($second['text'], '⚪ ')
    ) {
        throw new RuntimeException(
            'Panel statuses invalid.'
        );
    }

    echo "[OK] PANEL LIST KEYBOARD";
    echo PHP_EOL;

    // Test callback parser

    $callback = AdminKeyboard::parseCallback(
        $first['callback_data']
    );

    if (
        $callback === null ||
        $callback['action'] !== 'panel' ||
        $callback['panel_key'] !== AdminKeyboard::panelKey('eu-1')
    ) {
        throw new RuntimeException(
            'Callback parser invalid.'
        );
    }

    echo "[OK] CALLBACK PARSER";
    echo PHP_EOL;

    // Reject unsupported actions

    $invalid = AdminKeyboard::parseCallback(
        'nr:a:delete_everything'
    );

    if ($invalid !== null) {
        throw new RuntimeException(
            'Invalid callback accepted.'
        );
    }

    echo "[OK] INVALID CALLBACK REJECTED";
    echo PHP_EOL;

    // Test safe back navigation

    $back = AdminKeyboard::back();

    if (
        $back['inline_keyboard'][0][0]['callback_data']
        !== 'nr:a:home'
    ) {
        throw new RuntimeException(
            'Back button invalid.'
        );
    }

    echo "[OK] BACK NAVIGATION";
    echo PHP_EOL;

    echo PHP_EOL;

    echo "[SUCCESS] STAGE 21B ADMIN KEYBOARD TEST PASSED";

    echo PHP_EOL;

} catch (Throwable $error) {

    fwrite(
        STDERR,
        "[FAILED] " . $error->getMessage() . PHP_EOL
    );

    exit(1);
}