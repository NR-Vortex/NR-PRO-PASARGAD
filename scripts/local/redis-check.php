<?php

declare(strict_types=1);

use Predis\Client;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

echo PHP_EOL;
echo "NR PRO PASARGAD REDIS CHECK" . PHP_EOL;
echo "================================" . PHP_EOL;

try {

    $redis = new Client([
        'scheme' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 6380,
        'timeout' => 3,
    ]);

    $response = $redis->ping();

    if ((string) $response !== 'PONG') {
        throw new RuntimeException('Redis ping failed.');
    }

    echo "[OK] REDIS CONNECTED" . PHP_EOL;

    $key = 'nrpro:test:' . bin2hex(random_bytes(8));

    $redis->setex($key, 30, 'NR_REDIS_OK');

    $value = $redis->get($key);

    if ($value !== 'NR_REDIS_OK') {
        throw new RuntimeException('Redis SET/GET test failed.');
    }

    echo "[OK] REDIS SET/GET" . PHP_EOL;

    $redis->del([$key]);

    echo "[OK] REDIS CLEANUP" . PHP_EOL;

    echo "[SUCCESS] ALL REDIS TESTS PASSED" . PHP_EOL;

    exit(0);

} catch (Throwable $exception) {

    fwrite(
        STDERR,
        "[ERROR] REDIS TEST FAILED: "
        . $exception->getMessage()
        . PHP_EOL
    );

    exit(1);
}