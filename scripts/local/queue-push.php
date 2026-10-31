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

    $message = $argv[1] ?? 'NR PRO PASARGAD queue smoke test';

    if (strlen($message) > 1000) {
        throw new InvalidArgumentException('Message exceeds 1000 bytes.');
    }

    /** @var RedisQueue $queue */
    $queue = $container->get(RedisQueue::class);
    $id = $queue->enqueue('demo.echo', ['message' => $message]);

    echo "[OK] JOB ENQUEUED: {$id}" . PHP_EOL;
    echo "[INFO] TYPE: demo.echo" . PHP_EOL;
    $queue->disconnect();
} catch (Throwable $error) {
    fwrite(STDERR, '[ERROR] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
