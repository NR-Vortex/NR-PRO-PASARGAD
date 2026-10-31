<?php

declare(strict_types=1);

if (
    PHP_SAPI !== 'cli' ||
    ($argv[1] ?? '') !== '--isolated-local-redis'
) {
    fwrite(
        STDERR,
        "[BLOCKED] Isolated local test mode required.\n"
    );
    exit(2);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Queue\RedisQueue;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramAdminCallbackHandler;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramPanelToggleHandler;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramUpdateParser;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramWebhookInbox;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramWebhookReceiver;
use NRPro\Pasargad\Modules\Vpn\Security\PanelToggleFlow;
use Predis\Client;

$root = dirname(__DIR__, 2);

/*
 * Unique queue name prevents touching
 * existing bot jobs.
 */

$name = 'stage10_' . bin2hex(random_bytes(10));

$updateId = random_int(1000000, 1000000000);

$ids = [
    $updateId,
    $updateId + 1,
    $updateId + 2,
    $updateId + 3,
];

$keys = array_map(
    static fn($s) => "nrpro:queue:$name:$s",
    [
        'pending',
        'processing',
        'delayed',
        'failed',
    ]
);

foreach ($ids as $id) {
    $keys[] = "nrpro:telegram:updates:$name:$id";
}

$redis = null;
$queue = null;
$failed = false;

try {

    echo "\n=== STAGE 21C REDIS INTEGRATION ===\n";

    /*
     * LOCAL SAFETY
     */

    $env = new Environment($root . '/.env');

    $env->load();

    $appEnv = strtolower(
        trim($env->get('APP_ENV', ''))
    );

    $host = trim(
        $env->get('REDIS_HOST', '127.0.0.1')
    );

    $port = (int) $env->get('REDIS_PORT', '6380');

    $db = (int) $env->get('REDIS_DATABASE', '0');

    $pass = $env->get('REDIS_PASSWORD', '');

    if (
        !in_array(
            $appEnv,
            ['local', 'development', 'testing'],
            true
        ) ||
        !in_array(
            $host,
            ['127.0.0.1', 'localhost', '::1'],
            true
        ) ||
        $port < 1 ||
        $port > 65535 ||
        $db < 0 ||
        $env->get(
            'NR_PANEL_STATUS_WRITE_ENABLED',
            '0'
        ) !== '0'
    ) {
        throw new RuntimeException(
            'Local safety verification failed.'
        );
    }

    /*
     * CHECK WORKER INTEGRATION
     */

    $worker = @file_get_contents(
        $root . '/scripts/local/queue-worker.php'
    );

    foreach (
        [
            'STAGE21C_TOGGLE_WORKER',
            'STAGE21C_PART7_WRITE_GATEWAY',
            'TelegramPanelToggleHandler',
        ]
        as $marker
    ) {

        if (
            !is_string($worker) ||
            !str_contains($worker, $marker)
        ) {
            throw new RuntimeException(
                'Worker integration incomplete: ' . $marker
            );
        }
    }

    echo "[OK] LOCAL SAFETY VERIFIED\n";
    echo "[OK] WORKER INTEGRATION MARKERS FOUND\n";

    /*
     * CONNECT TO LOCAL REDIS
     */

    $args = [
        'scheme' => 'tcp',
        'host' => $host,
        'port' => $port,
        'database' => $db,
        'timeout' => 3,
        'read_write_timeout' => 3,
    ];

    if ($pass !== '') {
        $args['password'] = $pass;
    }

    $redis = new Client($args);

    if (
        (string) $redis->executeRaw(['PING'])
        !== 'PONG'
    ) {
        throw new RuntimeException(
            'Redis PING failed.'
        );
    }

    /*
     * Synthetic Telegram configuration.
     * No real bot token or real admin ID.
     */

    $secret = str_repeat('S', 48);

    $owner = 731947163;

    $panel = 'stage10-test-panel';

    $config = new Config([
        'telegram' => [
            'admin_id' => (string) $owner,
            'webhook_secret' => $secret,
        ],

        'redis' => [
            'host' => $host,
            'port' => $port,
            'database' => $db,
            'password' => $pass,
            'timeout' => 3,
        ],

        'queue' => [
            'name' => $name,
        ],
    ]);

    $queue = new RedisQueue($config);

    $receiver = new TelegramWebhookReceiver(
        $config,
        new TelegramUpdateParser(),
        new TelegramWebhookInbox($config)
    );

    $flow = new PanelToggleFlow(
        str_repeat('stage10-fake-test-key-', 3)
    );

    $toggle = new TelegramPanelToggleHandler(
        $config,
        $flow
    );

    $admin = new TelegramAdminCallbackHandler(
        $config
    );

    echo "[OK] LOCAL REDIS CONNECTED\n";
    echo "[OK] ISOLATED TEST QUEUE CREATED\n";

    /*
     * CREATE FAKE TELEGRAM CALLBACK
     */

    $makeBody = static function (
        int $id,
        string $data,
        int $user
    ): string {

        return json_encode([
            'update_id' => $id,

            'callback_query' => [
                'id' => 'test-' . $id,

                'from' => [
                    'id' => $user,
                ],

                'message' => [
                    'message_id' => 4909,

                    'chat' => [
                        'id' => $user,
                        'type' => 'private',
                    ],
                ],

                'data' => $data,
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    };

    $post = static fn(
        string $body,
        string $s
    ): array => $receiver->receive(
        'POST',
        'application/json',
        $s,
        $body
    );

    $requestData = $flow->panelActions(
        $panel,
        true
    )['inline_keyboard'][0][0]['callback_data'];

    $body = $makeBody(
        $ids[0],
        $requestData,
        $owner
    );

    /*
     * TEST 1: WEBHOOK SECURITY
     */

    if (
        $post($body, 'wrong')['status'] !== 403
    ) {
        throw new RuntimeException(
            'Invalid webhook secret accepted.'
        );
    }

    $unauthorized = $makeBody(
        $ids[1],
        $requestData,
        $owner + 1
    );

    if (
        $post(
            $unauthorized,
            $secret
        )['code'] !== 'ignored'
    ) {
        throw new RuntimeException(
            'Unauthorized admin accepted.'
        );
    }

    echo "[OK] WEBHOOK SECURITY VERIFIED\n";
    echo "[OK] UNAUTHORIZED ADMIN BLOCKED\n";

    /*
     * TEST 2: REDIS DEDUPLICATION
     */

    if (
        $post($body, $secret)['code'] !== 'queued' ||
        $post($body, $secret)['code'] !== 'duplicate'
    ) {
        throw new RuntimeException(
            'Redis deduplication failed.'
        );
    }

    if (
        $queue->stats()['pending'] !== 1
    ) {
        throw new RuntimeException(
            'Unexpected pending job count.'
        );
    }

    echo "[OK] CALLBACK ENQUEUED\n";
    echo "[OK] DUPLICATE UPDATE BLOCKED\n";

    /*
     * TEST PANEL DATA
     * No PostgreSQL connection.
     */

    $dbWrites = 0;

    $rows = static fn(
        int $who
    ): array => $who === $owner
        ? [
            [
                'panel_id' => $panel,
                'is_enabled' => true,
                'display_name' => 'TEST',
            ],
        ]
        : [];

    /*
     * Any attempted write fails the test.
     */

    $noWrite = static function () use (
        &$dbWrites
    ): string {

        ++$dbWrites;

        throw new RuntimeException(
            'Database write must not execute.'
        );
    };

    /*
     * Simulated worker consumer.
     */

    $claim = static function () use (
        $queue
    ): array {

        $item = $queue->claim(1);

        if (
            $item === null ||
            ($item['job']['type'] ?? null)
                !== 'telegram.command'
        ) {
            throw new RuntimeException(
                'Expected isolated Telegram job.'
            );
        }

        return $item;
    };

    $ack = static function (
        array $item
    ) use ($queue): void {

        if (
            !$queue->acknowledge(
                $item['raw']
            )
        ) {
            throw new RuntimeException(
                'Redis ACK failed.'
            );
        }
    };

    /*
     * TEST 3: REQUEST -> CONFIRMATION
     */

    $item = $claim();

    if (
        ($item['job']['payload']['command'] ?? '')
        !== 'admin_toggle'
    ) {
        throw new RuntimeException(
            'Toggle routing failed.'
        );
    }

    $reply = $toggle->render(
        $item['job']['payload'],
        $rows,
        $noWrite,
        false
    );

    $token = $reply[
        'reply_markup'
    ]['inline_keyboard'][0][0]['callback_data'] ?? null;

    if (
        !is_string($token) ||
        !str_starts_with(
            $token,
            'nr:t:c:'
        )
    ) {
        throw new RuntimeException(
            'Signed confirmation missing.'
        );
    }

    $ack($item);

    echo "[OK] QUEUE JOB CLAIMED\n";
    echo "[OK] SIGNED CONFIRMATION GENERATED\n";

    /*
     * TEST 4: CONFIRMATION -> WRITE GATE
     */

    $confirmation = $makeBody(
        $ids[2],
        $token,
        $owner
    );

    if (
        $post(
            $confirmation,
            $secret
        )['code'] !== 'queued'
    ) {
        throw new RuntimeException(
            'Confirmation enqueue failed.'
        );
    }

    $item = $claim();

    $reply = $toggle->render(
        $item['job']['payload'],
        $rows,
        $noWrite,
        false
    );

    if (
        !str_contains(
            $reply['text'],
            'غیرفعال'
        ) ||
        $dbWrites !== 0
    ) {
        throw new RuntimeException(
            'Write gate bypassed.'
        );
    }

    $ack($item);

    echo "[OK] CONFIRMATION PROCESSED\n";
    echo "[OK] DATABASE WRITES BLOCKED\n";

    /*
     * TEST 5: ADMIN HOME CALLBACK
     */

    $home = $makeBody(
        $ids[3],
        'nr:a:home',
        $owner
    );

    if (
        $post(
            $home,
            $secret
        )['code'] !== 'queued'
    ) {
        throw new RuntimeException(
            'Admin dashboard enqueue failed.'
        );
    }

    $item = $claim();

    $homeReply = $admin->render(
        $item['job']['payload'],

        static function (): array {
            throw new RuntimeException(
                'Unexpected database read.'
            );
        }
    );

    if (
        (
            $homeReply['reply_markup']
                ['inline_keyboard'][0][0]
                ['callback_data'] ?? ''
        ) !== 'nr:a:list'
    ) {
        throw new RuntimeException(
            'Dashboard callback failed.'
        );
    }

    $ack($item);

    echo "[OK] ADMIN DASHBOARD CALLBACK\n";

    /*
     * FINAL SAFETY CHECK
     */

    if (
        array_sum($queue->stats()) !== 0 ||
        $dbWrites !== 0
    ) {
        throw new RuntimeException(
            'Queue not drained or database write attempted.'
        );
    }

    echo "[OK] ISOLATED QUEUE DRAINED\n";

    echo "\n";
    echo "[SUCCESS] STAGE 21C PART 10 REDIS TEST PASSED\n";
    echo "[SAFE] NO TELEGRAM MESSAGES SENT\n";
    echo "[SAFE] NO POSTGRESQL WRITES\n";

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
     * Remove only unique test keys.
     * NEVER use FLUSHDB or FLUSHALL.
     */

    try {

        if ($queue !== null) {
            $queue->disconnect();
        }

        if ($redis !== null) {

            $redis->executeRaw(
                array_merge(
                    ['DEL'],
                    $keys
                )
            );

            $redis->disconnect();

            echo "[CLEANUP] ISOLATED TEST KEYS REMOVED\n";
        }

    } catch (Throwable) {

        $failed = true;

        fwrite(
            STDERR,
            "[CLEANUP FAILED] Some isolated keys may remain.\n"
        );
    }
}

exit($failed ? 1 : 0);