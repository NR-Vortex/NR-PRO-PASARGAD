<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__, 2)
    . '/vendor/autoload.php';

use NRPro\Pasargad\Modules\Vpn\Persistence\PanelStatusChangeService;

// Fake PDO: test MUST NOT access any database.

$pdo = new class extends PDO {

    public function __construct()
    {
    }

    public function inTransaction(): bool
    {
        throw new RuntimeException(
            'Test should not query a database.'
        );
    }
};

$service = new PanelStatusChangeService($pdo);

$operation = str_repeat('a', 32);

$invalid = [
    [0, 'eu-1', true, false, $operation],
    [123, '../eu', true, false, $operation],
    [123, 'eu-1', true, true, $operation],
    [123, 'eu-1', true, false, 'predictable'],
    [123, 'eu-1', true, false, str_repeat('z', 32)],
];

foreach ($invalid as $index => $params) {

    try {

        $service->change(...$params);

        throw new RuntimeException(
            'Invalid request passed: ' . $index
        );

    } catch (InvalidArgumentException) {

        echo '[OK] INVALID REQUEST BLOCKED '
            . ($index + 1)
            . PHP_EOL;
    }
}

echo '[SUCCESS] PANEL STATUS CHANGE VALIDATION TEST PASSED (OFFLINE)'
    . PHP_EOL;