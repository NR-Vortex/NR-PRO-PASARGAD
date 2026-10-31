<?php

declare(strict_types=1);

use NRPro\Pasargad\Core\Container\Container;
use NRPro\Pasargad\Core\Queue\RedisQueue;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$root = dirname(__DIR__, 2);

try {
    require $root . '/bootstrap/app.php';

    if (!isset($container) || !$container instanceof Container) {
        throw new RuntimeException('Container unavailable.');
    }

    /** @var RedisQueue $queue */
    $queue = $container->get(RedisQueue::class);
    $id = $queue->enqueue('demo.fail_once', [], 3);

    echo '[OK] RETRY DEMO ENQUEUED: ' . $id . PHP_EOL;
    echo '[INFO] The first run will schedule a 2-second retry.' . PHP_EOL;

    $queue->disconnect();
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
