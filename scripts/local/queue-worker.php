<?php

declare(strict_types=1);

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Container\Container;
use NRPro\Pasargad\Core\Database\Connection;
use NRPro\Pasargad\Core\Queue\RedisQueue;
use NRPro\Pasargad\Integrations\Telegram\Client\TelegramClient;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramAdminCallbackHandler;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramAdminCommandHandler;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramCommandHandler;
use NRPro\Pasargad\Integrations\Telegram\Keyboards\AdminKeyboard;
use NRPro\Pasargad\Modules\Vpn\Persistence\PanelRepository;
use NRPro\Pasargad\Modules\Vpn\Security\PanelCredentialCipher;
use NRPro\Pasargad\Modules\Vpn\Security\PanelToggleFlow;
use NRPro\Pasargad\Modules\Vpn\Persistence\PanelStatusWriteGateway;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramPanelToggleHandler;
use NRPro\Pasargad\Modules\Vpn\PanelDrivers;
use NRPro\Pasargad\Modules\Vpn\PanelManager;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$mode = $argv[1] ?? '--once';

$allowed = [
    '--once',
    '--loop',
    '--status',
    '--failed',
    '--recover',
    '--once-live',
    '--loop-live',
];

if (!in_array($mode, $allowed, true)) {
    fwrite(STDERR, "Invalid worker mode.\n");
    exit(2);
}

$live = in_array(
    $mode,
    ['--once-live', '--loop-live'],
    true
);

if (
    $live &&
    (
        count($argv) !== 3 ||
        $argv[2] !== 'I_AUTHORIZE_TELEGRAM_MESSAGES'
    )
) {
    fwrite(STDERR, "[BLOCKED] Live authorization required.\n");
    exit(2);
}

if (
    !$live &&
    $mode !== '--recover' &&
    count($argv) > 2
) {
    fwrite(STDERR, "[ERROR] Unexpected arguments.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$queue = null;

try {

    require $root . '/bootstrap/app.php';

    if (
        !isset($container) ||
        !$container instanceof Container
    ) {
        throw new RuntimeException(
            'Container unavailable.'
        );
    }

    $queue = $container->get(RedisQueue::class);

    /*
     * QUEUE STATUS
     */
    if ($mode === '--status') {

        $stats = $queue->stats();

        echo '[QUEUE] pending=' . $stats['pending']
            . ' processing=' . $stats['processing']
            . ' delayed=' . $stats['delayed']
            . ' failed=' . $stats['failed']
            . PHP_EOL;

        exit(0);
    }

    /*
     * FAILED JOBS
     */
    if ($mode === '--failed') {

        $jobs = $queue->failedJobs(10);

        if ($jobs === []) {
            echo '[FAILED] No failed jobs.' . PHP_EOL;
        }

        foreach ($jobs as $job) {

            echo '[FAILED] id=' . $job['id']
                . ' type=' . $job['type']
                . ' attempts=' . $job['attempts']
                . ' reason=' . $job['error']
                . PHP_EOL;
        }

        exit(0);
    }

    /*
     * MANUAL RECOVERY
     */
    if ($mode === '--recover') {

        if (
            count($argv) !== 3 ||
            $argv[2] !== 'I_STOPPED_ALL_WORKERS'
        ) {
            throw new RuntimeException(
                'Stop other workers before recovery.'
            );
        }

        echo '[RECOVERED] '
            . $queue->recover()
            . ' jobs'
            . PHP_EOL;

        exit(0);
    }

    $config = $container->get(Config::class);

    $client = $container->get(TelegramClient::class);

    /*
     * READ-ONLY PANEL METADATA PROVIDER
     */
    $listPanels = static function (
        int $ownerId
    ) use ($container): array {

        try {

            $environment = $container->get(
                Environment::class
            );

            $cipher = new PanelCredentialCipher(
                $environment->get(
                    'NR_PANEL_ENCRYPTION_KEY',
                    ''
                )
            );

            $database = $container->get(
                Connection::class
            );

            $repository = new PanelRepository(
                $database->pdo(),
                $cipher
            );

            return $repository->listMetadata($ownerId);

        } catch (Throwable) {

            throw new RuntimeException(
                'Panel metadata is temporarily unavailable.'
            );
        }
    };


    $checkPanel = static function (
        int $ownerId,
        string $panelId
    ) use ($container): string {

        try {

            $environment = $container->get(
                Environment::class
            );

            $cipher = new PanelCredentialCipher(
                $environment->get(
                    'NR_PANEL_ENCRYPTION_KEY',
                    ''
                )
            );

            $database = $container->get(
                Connection::class
            );

            $repository = new PanelRepository(
                $database->pdo(),
                $cipher
            );

            $connection = $repository->getEnabledConnection(
                $ownerId,
                $panelId
            );

            if ($connection === null) {
                return 'unavailable';
            }

            $manager = new PanelManager(
                PanelDrivers::standard()
            );

            $result = $manager->ping($connection);

            if ($result->success) {
                return 'online';
            }

            return match ($result->code) {

                'authentication_failed',
                'permission_denied' => 'auth_failed',

                'transport_error',
                'upstream_unavailable' => 'unavailable',

                default => 'error',
            };

        } catch (Throwable) {

            return 'error';
        }
    };


$once = in_array(
        $mode,
        ['--once', '--once-live'],
        true
    );

    echo '[WORKER] '
        . ($once ? 'once' : 'loop')
        . ($live ? ' (live enabled)' : ' (live disabled)')
        . PHP_EOL;

    do {

        $promoted = $queue->promoteDue();

        if ($promoted > 0) {
            echo '[READY] ' . $promoted . ' delayed jobs'
                . PHP_EOL;
        }

        $claimed = $queue->claim(1);

        if ($claimed === null) {

            if ($once) {
                echo '[INFO] No ready jobs.' . PHP_EOL;
            }

            continue;
        }

        $job = $claimed['job'];
        $raw = $claimed['raw'];

        $id = (string) (
            $job['id'] ?? 'unknown'
        );

        $type = (string) (
            $job['type'] ?? 'unknown'
        );

        /*
         * PROTECT TELEGRAM JOBS
         */
        if (
            $type === 'telegram.command' &&
            !$live
        ) {

            if (!$queue->release($raw)) {
                throw new RuntimeException(
                    'Cannot release Telegram job.'
                );
            }

            fwrite(
                STDERR,
                "[PAUSED] Telegram job preserved.\n"
            );

            exit(3);
        }

        $sendAttempted = false;

        try {

            switch ($type) {

                /*
                 * DEMO ECHO
                 */
                case 'demo.echo':

                    $payload = $job['payload'] ?? [];

                    if (
                        !is_array($payload) ||
                        !is_string(
                            $payload['message'] ?? null
                        )
                    ) {
                        throw new InvalidArgumentException(
                            'Invalid demo.echo payload.'
                        );
                    }

                    echo '[JOB] '
                        . $id
                        . ' | '
                        . $payload['message']
                        . PHP_EOL;

                    break;

                /*
                 * DEMO RETRY
                 */
                case 'demo.fail_once':

                    if (
                        (int) ($job['attempts'] ?? 0)
                        === 0
                    ) {
                        throw new RuntimeException(
                            'Demo transient error.'
                        );
                    }

                    echo '[JOB] '
                        . $id
                        . ' | demo recovered'
                        . PHP_EOL;

                    break;

                /*
                 * TELEGRAM COMMANDS AND CALLBACKS
                 */
                case 'telegram.command':

                    $payload = $job['payload'] ?? null;

                    if (!is_array($payload)) {
                        throw new InvalidArgumentException(
                            'Invalid Telegram command payload.'
                        );
                    }

                    $command = $payload['command']
                        ?? null;

                    /*
                     * INLINE BUTTON CALLBACKS
                     */
                    if ($command === 'admin_callback') {

                        $handler = new TelegramAdminCallbackHandler(
                            $config
                        );

                        $reply = $handler->render(
                            $payload,
                            $listPanels, $checkPanel
                        );
                        // STAGE21C_TOGGLE_PANEL_MENU
                        if (($payload['action'] ?? null) === 'panel') {

                            $environment = $container->get(
                                Environment::class
                            );

                            $toggleFlow = new PanelToggleFlow(
                                $environment->get(
                                    'NR_PANEL_ENCRYPTION_KEY',
                                    ''
                                )
                            );

                            $owner = $payload['user_id'];

                            $selectedKey = $payload['panel_key'];

                            foreach ($listPanels($owner) as $row) {

                                if (
                                    !is_array($row)
                                    || !is_string(
                                        $row['panel_id'] ?? null
                                    )
                                ) {
                                    continue;
                                }

                                $panelId = $row['panel_id'];

                                if (!preg_match(
                                    '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',
                                    $panelId
                                )) {
                                    continue;
                                }

                                if (!hash_equals(
                                    AdminKeyboard::panelKey($panelId),
                                    $selectedKey
                                )) {
                                    continue;
                                }

                                $enabled = in_array(
                                    $row['is_enabled'] ?? false,
                                    [true, 't', '1', 1],
                                    true
                                );

                                $reply['reply_markup'] =
                                    $toggleFlow->panelActions(
                                        $panelId,
                                        $enabled
                                    );

                                break;
                            }
                        }


                        // No blind retry after an API call.
                        $sendAttempted = true;

                        $client->answerCallbackQuery(
                            $reply['callback_id']
                        );

                        $client->editMessageText(
                            $reply['chat_id'],
                            $reply['message_id'],
                            $reply['text'],
                            $reply['reply_markup']
                        );

                        echo '[EDITED] Admin callback '
                            . $id
                            . PHP_EOL;

                    /*
                     * REGULAR TELEGRAM COMMANDS
                     */
                    } elseif ($command === 'admin_toggle') {

                        // STAGE21C_TOGGLE_WORKER
                        $environment = $container->get(
                            Environment::class
                        );

                        $flow = new PanelToggleFlow(
                            $environment->get(
                                'NR_PANEL_ENCRYPTION_KEY',
                                ''
                            )
                        );

                        $handler = new TelegramPanelToggleHandler(
                            $config,
                            $flow
                        );

                        // STAGE21C_PART7_WRITE_GATEWAY
                        // Database writes remain disabled by default.

                        $gateway = new PanelStatusWriteGateway(
                            $environment->get(
                                'APP_ENV',
                                'production'
                            ),
                            $environment->get(
                                'DB_HOST',
                                ''
                            ),
                            $environment->get(
                                'NR_PANEL_STATUS_WRITE_ENABLED',
                                '0'
                            ),
                            $environment->get(
                                'NR_PANEL_STATUS_WRITE_ACK',
                                ''
                            ),
                            static fn (): PDO =>
                                $container->get(
                                    Connection::class
                                )->pdo()
                        );

                        $changeStatus = static function (
                            int $owner,
                            string $panelId,
                            bool $previous,
                            bool $target,
                            string $operationId
                        ) use ($gateway): string {

                            return $gateway->change(
                                $owner,
                                $panelId,
                                $previous,
                                $target,
                                $operationId
                            );
                        };

                        $reply = $handler->render(
                            $payload,
                            $listPanels,
                            $changeStatus,
                            $gateway->enabled()
                        );
                        $sendAttempted = true;

                        $client->answerCallbackQuery(
                            $reply['callback_id']
                        );

                        $client->editMessageText(
                            $reply['chat_id'],
                            $reply['message_id'],
                            $reply['text'],
                            $reply['reply_markup']
                        );

                        echo '[EDITED] Admin toggle '
                            . $id
                            . PHP_EOL;

                    } else {if (in_array(
                            $command,
                            ['admin', 'panels', 'panel'],
                            true
                        )) {

                            $handler = new TelegramAdminCommandHandler(
                                $config
                            );

                            $reply = $handler->render(
                                $payload,
                                $listPanels
                            );

                            $markup = match ($command) {

                                'admin' =>
                                    AdminKeyboard::dashboard(),

                                'panels' =>
                                    AdminKeyboard::panelList(
                                        $listPanels(
                                            $payload['user_id']
                                        )
                                    ),

                                'panel' =>
                                    AdminKeyboard::panelDetails(),
                            };

                        } else {

                            $reply = (
                                new TelegramCommandHandler()
                            )->render($payload);

                            $markup = null;
                        }

                        $sendAttempted = true;

                        $result = $client->sendMessage(
                            $reply['chat_id'],
                            $reply['text'],
                            $markup
                        );

                        if (
                            !is_int(
                                $result['message_id'] ?? null
                            )
                        ) {
                            throw new RuntimeException(
                                'Invalid Telegram message result.'
                            );
                        }

                        echo '[SENT] Telegram job '
                            . $id
                            . PHP_EOL;
                    }

                    break;

                default:

                    throw new InvalidArgumentException(
                        'Unsupported job type.'
                    );
            }

        } catch (Throwable $error) {

            /*
             * Avoid duplicate Telegram messages.
             */
            $permanent =
                $error instanceof InvalidArgumentException
                || (
                    $type === 'telegram.command' &&
                    $sendAttempted
                );

            /*
             * Do not store sensitive upstream errors.
             */
            $reason = $type === 'telegram.command'
                ? 'Telegram job failed; details suppressed.'
                : $error->getMessage();

            $state = $queue->reject(
                $raw,
                $job,
                $reason,
                $permanent
            );

            echo '[JOB '
                . strtoupper($state)
                . '] '
                . $id
                . PHP_EOL;

            continue;
        }

        /*
         * ACKNOWLEDGE COMPLETED JOB
         */
        if (!$queue->acknowledge($raw)) {
            throw new RuntimeException(
                'Job completed but acknowledgment failed.'
            );
        }

        echo '[DONE] ' . $id . PHP_EOL;

    } while (!$once);

} catch (Throwable $error) {

    fwrite(
        STDERR,
        '[ERROR] ' . $error->getMessage() . PHP_EOL
    );

    exit(1);

} finally {

    if ($queue instanceof RedisQueue) {
        $queue->disconnect();
    }
}