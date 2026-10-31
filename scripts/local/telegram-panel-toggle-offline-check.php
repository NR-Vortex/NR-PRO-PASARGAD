<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Modules\Vpn\Security\PanelToggleFlow;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramPanelToggleHandler;

try {

    echo "=== STAGE 21C PART 5 OFFLINE TEST ===\n";

    $config = new Config([
        'telegram' => [
            'admin_id' => '123456',
        ],
    ]);

    $flow = new PanelToggleFlow(
        str_repeat('offline-test-secret-', 3)
    );

    $handler = new TelegramPanelToggleHandler(
        $config,
        $flow
    );

    echo "[OK] HANDLER INITIALIZED\n";

    $panelId = 'eu-1';

    $keyboard = $flow->panelActions(
        $panelId,
        true
    );

    $requestData = $keyboard[
        'inline_keyboard'
    ][0][0]['callback_data'];

    $job = [
        'update_id' => 1,
        'user_id' => 123456,
        'chat_id' => 123456,
        'command' => 'admin_toggle',
        'callback_id' => 'test-1',
        'message_id' => 99,
        'callback_data' => $requestData,
    ];

    $listPanels = static function (
        int $owner
    ): array {

        if ($owner !== 123456) {
            throw new RuntimeException(
                'Wrong panel owner.'
            );
        }

        return [
            [
                'panel_id' => 'eu-1',
                'is_enabled' => true,
                'display_name' => 'Europe',
            ],
        ];
    };

    $changes = 0;

    $changeStatus = static function (
        int $owner,
        string $id,
        bool $previous,
        bool $target,
        string $operationId
    ) use (&$changes): string {

        ++$changes;

        return 'changed';
    };

    // STEP 1: Request confirmation.

    $request = $handler->render(
        $job,
        $listPanels,
        $changeStatus,
        false
    );

    if (!str_contains(
        $request['text'],
        'تأیید'
    )) {
        throw new RuntimeException(
            'Confirmation screen not generated.'
        );
    }

    echo "[OK] CONFIRMATION SCREEN\n";

    // STEP 2: Obtain confirmation token.

    $token = $request[
        'reply_markup'
    ]['inline_keyboard'][0][0]['callback_data'] ?? '';

    if (
        !is_string($token) ||
        !str_starts_with($token, 'nr:t:c:')
    ) {
        throw new RuntimeException(
            'Signed confirmation token missing.'
        );
    }

    $job['callback_data'] = $token;

    echo "[OK] SIGNED CONFIRMATION TOKEN\n";

    // STEP 3: Verify that DB writes are disabled.

    $result = $handler->render(
        $job,
        $listPanels,
        $changeStatus,
        false
    );

    if ($changes !== 0) {
        throw new RuntimeException(
            'Database write gate bypassed.'
        );
    }

    if (!str_contains(
        $result['text'],
        'غیرفعال'
    )) {
        throw new RuntimeException(
            'Write protection response missing.'
        );
    }

    echo "[OK] DATABASE WRITE BLOCKED\n";

    // STEP 4: Unauthorized user must be rejected.

    $job['user_id'] = 999999;

    $blocked = false;

    try {

        $handler->render(
            $job,
            $listPanels,
            $changeStatus,
            false
        );

    } catch (InvalidArgumentException) {

        $blocked = true;
    }

    if (!$blocked) {
        throw new RuntimeException(
            'Unauthorized user was accepted.'
        );
    }

    if ($changes !== 0) {
        throw new RuntimeException(
            'Unexpected status modification.'
        );
    }

    echo "[OK] UNAUTHORIZED USER BLOCKED\n";

    echo "\n";
    echo "[SUCCESS] STAGE 21C PART 5 OFFLINE TEST PASSED\n";
    echo "[SAFE] NO DATABASE OR TELEGRAM REQUESTS\n";

} catch (Throwable $error) {

    fwrite(
        STDERR,
        '[FAILED] '
        . $error->getMessage()
        . PHP_EOL
    );

    exit(1);
}