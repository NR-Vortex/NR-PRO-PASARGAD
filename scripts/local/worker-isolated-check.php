<?php

declare(strict_types=1);

if (
    PHP_SAPI !== 'cli' ||
    ($argv[1] ?? '') !== '--offline-worker'
) {
    fwrite(STDERR, "[BLOCKED] Use --offline-worker.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Queue\RedisQueue;
use Predis\Client;

/**
 * Execute the REAL queue-worker.php in safe mode.
 *
 * Never pass --once-live or --loop-live.
 */
function runSafeWorker(
    string $root,
    string $mode
): array {

    $worker = $root . '/scripts/local/queue-worker.php';

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        [PHP_BINARY, $worker, $mode],
        $descriptors,
        $pipes,
        $root,
        null,
        PHP_OS_FAMILY === 'Windows'
            ? ['bypass_shell' => true]
            : []
    );

    if (!is_resource($process)) {
        throw new RuntimeException(
            'Unable to start PHP worker.'
        );
    }

    fclose($pipes[0]);

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';

    $exitCode = -1;
    $timedOut = false;

    $deadline = microtime(true) + 20;

    try {

        while (true) {

            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $status = proc_get_status($process);

            if (!$status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }

            if (microtime(true) > $deadline) {

                $timedOut = true;

                proc_terminate($process);

                break;
            }

            usleep(50000);
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

    } finally {

        fclose($pipes[1]);
        fclose($pipes[2]);

        $closedCode = proc_close($process);

        if ($exitCode < 0 && !$timedOut) {
            $exitCode = $closedCode;
        }
    }

    if ($timedOut) {
        throw new RuntimeException(
            'Worker execution timed out.'
        );
    }

    return [
        'exit' => $exitCode,
        'out' => $stdout,
        'err' => $stderr,
    ];
}

$redis = null;
$queue = null;

$canClean = false;
$failed = false;

$oldQueueName = getenv('QUEUE_NAME');

try {

    echo "\n=== REAL WORKER ISOLATED TEST ===\n";

    if (!function_exists('proc_open')) {
        throw new RuntimeException(
            'PHP proc_open is disabled.'
        );
    }

    /*
     * STEP 1: VERIFY LOCAL ENVIRONMENT
     */

    $env = new Environment($root . '/.env');
    $env->load();

    $appEnv = strtolower(
        trim($env->get('APP_ENV', ''))
    );

    $redisHost = trim(
        $env->get('REDIS_HOST', '127.0.0.1')
    );

    $redisPort = (int) $env->get(
        'REDIS_PORT',
        '6380'
    );

    $redisDb = (int) $env->get(
        'REDIS_DATABASE',
        '0'
    );

    $redisPassword = $env->get(
        'REDIS_PASSWORD',
        ''
    );

    $writeEnabled = $env->get(
        'NR_PANEL_STATUS_WRITE_ENABLED',
        '0'
    );

    if (
        !in_array(
            $appEnv,
            ['local', 'development', 'testing'],
            true
        ) ||
        !in_array(
            $redisHost,
            ['127.0.0.1', 'localhost', '::1'],
            true
        ) ||
        $redisPort !== 6380 ||
        $redisDb < 0 ||
        $writeEnabled !== '0'
    ) {
        throw new RuntimeException(
            'Local safety verification failed.'
        );
    }

    echo "[OK] LOCAL ENVIRONMENT VERIFIED\n";
    echo "[OK] DATABASE WRITE GATE DISABLED\n";

    /*
     * STEP 2: VERIFY REAL WORKER SAFE GUARD
     */

    $workerSource = file_get_contents(
        $root . '/scripts/local/queue-worker.php'
    );

    $guardPattern = <<<'REGEX'
~if\s*\(\s*\$type\s*===\s*['"]telegram\.command['"]\s*&&\s*!\s*\$live\s*\)~
REGEX;

    if (
        !is_string($workerSource) ||
        preg_match(
            $guardPattern,
            $workerSource
        ) !== 1 ||
        !str_contains(
            $workerSource,
            '$queue->release($raw)'
        ) ||
        !str_contains(
            $workerSource,
            "case 'demo.echo':"
        )
    ) {
        throw new RuntimeException(
            'Worker safety guard missing. Execution blocked.'
        );
    }

    echo "[OK] WORKER SAFETY GUARD VERIFIED\n";

    /*
     * STEP 3: CREATE RANDOM ISOLATED QUEUE
     */

    $queueName = 'stage11_'
        . bin2hex(random_bytes(10));

    $prefix = 'nrpro:queue:'
        . $queueName
        . ':';

    $keys = array_map(
        static fn(string $name): string =>
            $prefix . $name,
        [
            'pending',
            'processing',
            'delayed',
            'failed',
        ]
    );

    /*
     * STEP 4: CONNECT TO LOCAL REDIS
     */

    $parameters = [
        'scheme' => 'tcp',
        'host' => $redisHost,
        'port' => $redisPort,
        'database' => $redisDb,
        'timeout' => 3,
        'read_write_timeout' => 3,
    ];

    if ($redisPassword !== '') {
        $parameters['password'] = $redisPassword;
    }

    $redis = new Client($parameters);

    if (
        (string) $redis->executeRaw(['PING'])
        !== 'PONG'
    ) {
        throw new RuntimeException(
            'Redis connection failed.'
        );
    }

    foreach ($keys as $key) {

        if (
            (int) $redis->executeRaw([
                'EXISTS',
                $key
            ]) !== 0
        ) {
            throw new RuntimeException(
                'Isolated queue key collision.'
            );
        }
    }

    $canClean = true;

    echo "[OK] LOCAL REDIS CONNECTED\n";

    $config = new Config([
        'redis' => [
            'host' => $redisHost,
            'port' => $redisPort,
            'database' => $redisDb,
            'password' => $redisPassword,
            'timeout' => 3,
        ],

        'queue' => [
            'name' => $queueName,
        ],
    ]);

    $queue = new RedisQueue($config);

    /*
     * Make ONLY the child Worker use our test queue.
     * Never modify the .env file.
     */

    if (!putenv('QUEUE_NAME=' . $queueName)) {
        throw new RuntimeException(
            'Unable to isolate Worker queue.'
        );
    }

    echo "[OK] ISOLATED QUEUE CREATED\n";

    /*
     * STEP 5: CREATE SAFE DEMO JOB
     */

    $marker = 'nr-stage11-'
        . bin2hex(random_bytes(6));

    $queue->enqueue(
        'demo.echo',
        [
            'message' => $marker,
        ]
    );

    echo "[OK] DEMO JOB ENQUEUED\n";

    /*
     * STEP 6: EXECUTE REAL WORKER
     *
     * --once is SAFE MODE.
     */

    $run = runSafeWorker(
        $root,
        '--once'
    );

    if (
        $run['exit'] !== 0 ||
        !str_contains(
            $run['out'],
            $marker
        ) ||
        !str_contains(
            $run['out'],
            '[DONE]'
        ) ||
        array_sum(
            $queue->stats()
        ) !== 0
    ) {
        throw new RuntimeException(
            'Real Worker demo job failed. Exit: '
            . $run['exit']
            . ' '
            . trim($run['err'])
        );
    }

    echo "[OK] REAL WORKER EXECUTED\n";
    echo "[OK] DEMO JOB PROCESSED\n";
    echo "[OK] JOB ACKNOWLEDGED\n";

    /*
     * STEP 7: CREATE TELEGRAM TEST JOB
     *
     * No real Telegram identity or token needed.
     */

    $telegramJobId = $queue->enqueue(
        'telegram.command',
        [
            'command' => 'admin_toggle',
            'user_id' => 987654321,
            'chat_id' => 987654321,
            'update_id' => 100,
            'callback_id' => 'offline-test',
            'message_id' => 44,
            'callback_data' =>
                'nr:t:r:0:'
                . str_repeat('0', 20),
        ]
    );

    $before = (string) $redis->executeRaw([
        'LINDEX',
        $keys[0],
        '0',
    ]);

    /*
     * STEP 8: VERIFY TELEGRAM SAFETY
     *
     * Real Worker must reject execution
     * and preserve the job in Redis.
     */

    $run = runSafeWorker(
        $root,
        '--once'
    );

    $after = (string) $redis->executeRaw([
        'LINDEX',
        $keys[0],
        '0',
    ]);

    $stats = $queue->stats();

    if (
        $run['exit'] !== 3 ||
        !str_contains(
            $run['out'] . $run['err'],
            '[PAUSED]'
        ) ||
        $before === '' ||
        !hash_equals($before, $after) ||
        ($stats['pending'] ?? -1) !== 1 ||
        ($stats['processing'] ?? -1) !== 0 ||
        ($stats['delayed'] ?? -1) !== 0 ||
        ($stats['failed'] ?? -1) !== 0 ||
        (
            json_decode($after, true)['id'] ?? null
        ) !== $telegramJobId
    ) {
        throw new RuntimeException(
            'Telegram safety check failed. Exit: '
            . $run['exit']
            . ' '
            . trim($run['err'])
        );
    }

    echo "[OK] TELEGRAM JOB BLOCKED\n";
    echo "[OK] TELEGRAM JOB PRESERVED\n";
    echo "[OK] NO LIVE TELEGRAM EXECUTION\n";

    /*
     * STEP 9: TEST WORKER STATUS COMMAND
     */

    $status = runSafeWorker(
        $root,
        '--status'
    );

    if (
        $status['exit'] !== 0 ||
        !str_contains(
            $status['out'],
            'pending=1'
        )
    ) {
        throw new RuntimeException(
            'Worker status check failed.'
        );
    }

    echo "[OK] WORKER STATUS VERIFIED\n";

    /*
     * SUCCESS
     */

    echo "\n";
    echo "[SUCCESS] STAGE 21C PART 11 PASSED\n";
    echo "[SAFE] NO TELEGRAM MESSAGES SENT\n";
    echo "[SAFE] NO POSTGRESQL WRITES\n";
    echo "[SAFE] NO VPS CHANGES\n";

} catch (Throwable $error) {

    $failed = true;

    fwrite(
        STDERR,
        '[FAILED] '
        . $error->getMessage()
        . PHP_EOL
    );

} finally {

    /*
     * CLEAN UP ONLY THE UNIQUE TEST QUEUE.
     *
     * Never FLUSHDB or FLUSHALL.
     */

    if ($queue !== null) {
        $queue->disconnect();
    }

    if ($redis !== null) {

        if ($canClean) {

            try {

                $redis->executeRaw(
                    array_merge(
                        ['DEL'],
                        $keys
                    )
                );

                echo "[CLEANUP] ISOLATED QUEUE REMOVED\n";

            } catch (Throwable) {

                $failed = true;

                fwrite(
                    STDERR,
                    "[FAILED] Test queue cleanup failed.\n"
                );
            }
        }

        $redis->disconnect();
    }

    /*
     * Restore original process environment.
     */

    if ($oldQueueName === false) {
        putenv('QUEUE_NAME');
    } else {
        putenv(
            'QUEUE_NAME=' . $oldQueueName
        );
    }
}

exit($failed ? 1 : 0);