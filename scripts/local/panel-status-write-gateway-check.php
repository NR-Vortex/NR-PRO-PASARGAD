<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2)
    . '/vendor/autoload.php';

use NRPro\Pasargad\Modules\Vpn\Persistence\PanelStatusWriteGateway;

try {

    echo "=== PANEL STATUS WRITE GATEWAY TEST ===\n";

    $connectionCalls = 0;

    $connect = static function () use (
        &$connectionCalls
    ): PDO {

        ++$connectionCalls;

        throw new RuntimeException(
            'Test must not connect to a database.'
        );
    };

    /*
     * Every unsafe configuration must fail closed.
     */

    $cases = [
        [
            'local',
            '127.0.0.1',
            '0',
            'I_ACCEPT_LOCAL_DB_CHANGES',
        ],
        [
            'local',
            '127.0.0.1',
            '1',
            '',
        ],
        [
            'production',
            '127.0.0.1',
            '1',
            'I_ACCEPT_LOCAL_DB_CHANGES',
        ],
        [
            'local',
            'db',
            '1',
            'I_ACCEPT_LOCAL_DB_CHANGES',
        ],
        [
            'local',
            '192.168.1.10',
            '1',
            'I_ACCEPT_LOCAL_DB_CHANGES',
        ],
    ];

    foreach ($cases as $index => $case) {

        $gateway = new PanelStatusWriteGateway(
            ...array_merge(
                $case,
                [$connect]
            )
        );

        if ($gateway->enabled()) {
            throw new RuntimeException(
                'Unsafe configuration accepted: '
                . $index
            );
        }

        $blocked = false;

        try {

            $gateway->change(
                123456,
                'eu-1',
                true,
                false,
                str_repeat('a', 32)
            );

        } catch (RuntimeException $error) {

            if (
                $error->getMessage()
                !== 'Panel status writes are disabled.'
            ) {
                throw $error;
            }

            $blocked = true;
        }

        if (!$blocked) {
            throw new RuntimeException(
                'Write gate bypassed.'
            );
        }
    }

    echo "[OK] UNSAFE CONFIGURATIONS BLOCKED\n";

    /*
     * No disabled request may open a connection.
     */

    if ($connectionCalls !== 0) {
        throw new RuntimeException(
            'Unexpected database connection.'
        );
    }

    echo "[OK] ZERO DATABASE CONNECTIONS\n";

    /*
     * Explicit local authorization is recognized.
     * Do not execute a database change here.
     */

    $gateway = new PanelStatusWriteGateway(
        'local',
        '127.0.0.1',
        '1',
        'I_ACCEPT_LOCAL_DB_CHANGES',
        $connect
    );

    if (!$gateway->enabled()) {
        throw new RuntimeException(
            'Valid local configuration rejected.'
        );
    }

    echo "[OK] LOCAL OPT-IN VALIDATED\n";

    if ($connectionCalls !== 0) {
        throw new RuntimeException(
            'Database was accessed.'
        );
    }

    echo "\n";

    echo "[SUCCESS] STAGE 21C PART 7 GATE TEST PASSED\n";

    echo "[SAFE] NO DATABASE CHANGES\n";

} catch (Throwable $error) {

    fwrite(
        STDERR,
        '[FAILED] '
        . $error->getMessage()
        . PHP_EOL
    );

    exit(1);
}