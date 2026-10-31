<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__, 2)
    . '/src/Modules/Vpn/Security/PanelToggleFlow.php';

use NRPro\Pasargad\Modules\Vpn\Security\PanelToggleFlow;

try {
    // Fake secret: never read the real .env.
    $flow = new PanelToggleFlow(
        str_repeat('offline-test-key-', 3)
    );

    $panelId = 'eu-1';
    $owner = 123456;
    $message = 77;
    $now = 1793440000;

    // TEST 1: REQUEST BUTTON

    $actions = $flow->panelActions(
        $panelId,
        true
    );

    $request = $actions[
        'inline_keyboard'
    ][0][0]['callback_data'];

    if (
        $flow->validateRequest(
            $request,
            $panelId,
            true
        ) !== false
        ||
        $flow->validateRequest(
            $request,
            $panelId,
            false
        ) !== null
        ||
        $flow->validateRequest(
            $request,
            'us-2',
            true
        ) !== null
    ) {
        throw new RuntimeException(
            'Request validation failure.'
        );
    }

    echo "[OK] PANEL REQUEST VALIDATED\n";

    // TEST 2: SIGNED CONFIRMATION

    $keyboard = $flow->confirmationKeyboard(
        $owner,
        $message,
        $panelId,
        false,
        $now
    );

    $token = $keyboard[
        'inline_keyboard'
    ][0][0]['callback_data'];

    if (strlen($token) > 64) {
        throw new RuntimeException(
            'Telegram callback length exceeded.'
        );
    }

    $verified = $flow->verifyConfirmation(
        $token,
        $owner,
        $message,
        $panelId,
        $now + 60
    );

    if (
        ($verified['target_enabled'] ?? null) !== false
        ||
        ($verified['expected_enabled'] ?? null) !== true
        ||
        !preg_match(
            '/^[a-f0-9]{32}$/D',
            $verified['operation_id'] ?? ''
        )
    ) {
        throw new RuntimeException(
            'Valid confirmation rejected.'
        );
    }

    echo "[OK] SIGNED CONFIRMATION ACCEPTED\n";

    // TEST 3: TAMPERING

    $last = strlen($token) - 1;

    $tampered = substr_replace(
        $token,
        $token[$last] === 'a' ? 'b' : 'a',
        $last,
        1
    );

    $invalidCases = [
        [$tampered, $owner, $message, $panelId, $now + 60],
        [$token, 999, $message, $panelId, $now + 60],
        [$token, $owner, 999, $panelId, $now + 60],
        [$token, $owner, $message, 'us-2', $now + 60],
        [$token, $owner, $message, $panelId, $now + 181],
        [$token, $owner, $message, $panelId, $now - 16],
    ];

    foreach ($invalidCases as $case) {
        if (
            $flow->verifyConfirmation(...$case)
            !== null
        ) {
            throw new RuntimeException(
                'Invalid confirmation accepted.'
            );
        }
    }

    echo "[OK] INVALID CONFIRMATIONS REJECTED\n";

    // TEST 4: STABLE OPERATION ID

    $again = $flow->verifyConfirmation(
        $token,
        $owner,
        $message,
        $panelId,
        $now + 75
    );

    if (
        $again['operation_id']
        !== $verified['operation_id']
    ) {
        throw new RuntimeException(
            'Operation ID changed.'
        );
    }

    echo "[OK] DUPLICATE OPERATION ID STABLE\n";

    echo "\n";
    echo "[SUCCESS] STAGE 21C PART 4 OFFLINE TEST PASSED\n";
    echo "[SAFE] NO DATABASE OR TELEGRAM USED\n";

} catch (Throwable $error) {
    fwrite(
        STDERR,
        '[FAILED] '
        . $error->getMessage()
        . PHP_EOL
    );

    exit(1);
}