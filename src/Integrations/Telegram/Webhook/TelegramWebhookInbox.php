<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Webhook;

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramAdminAccess;
use NRPro\Pasargad\Integrations\Telegram\Keyboards\AdminKeyboard;
use Predis\Client;
use RuntimeException;

final class TelegramWebhookInbox implements TelegramWebhookStore
{
    private ?Client $redis = null;

    private string $queueName;

    public function __construct(
        private readonly Config $config
    ) {
        $this->queueName = (string) $config->get(
            'queue.name',
            'default'
        );

        if (!preg_match(
            '/^[A-Za-z0-9_-]{1,64}$/D',
            $this->queueName
        )) {
            throw new RuntimeException(
                'Invalid Telegram queue name.'
            );
        }
    }

    private function redis(): Client
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        $host = (string) $this->config->get(
            'redis.host',
            '127.0.0.1'
        );

        $port = (int) $this->config->get(
            'redis.port',
            6380
        );

        $db = (int) $this->config->get(
            'redis.database',
            0
        );

        $timeout = (float) $this->config->get(
            'redis.timeout',
            3
        );

        $password = (string) $this->config->get(
            'redis.password',
            ''
        );

        if (
            $host === '' ||
            $port < 1 ||
            $port > 65535 ||
            $db < 0 ||
            $timeout <= 1
        ) {
            throw new RuntimeException(
                'Invalid Redis configuration.'
            );
        }

        $args = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'database' => $db,
            'timeout' => $timeout,
            'read_write_timeout' => $timeout,
        ];

        if ($password !== '') {
            $args['password'] = $password;
        }

        return $this->redis = new Client($args);
    }

    public function enqueueOnce(
        int $updateId,
        array $payload
    ): bool {

        /*
         * Validate update.
         */
        if (
            $updateId < 0 ||
            ($payload['update_id'] ?? null) !== $updateId
        ) {
            throw new RuntimeException(
                'Invalid Telegram update ID.'
            );
        }

        $command = $payload['command'] ?? null;

        /*
         * Allowed commands.
         */
        $allowed = [
            'start',
            'help',
            'admin',
            'panels',
            'panel',
            'admin_callback', 'admin_toggle',
        ];

        if (!in_array($command, $allowed, true)) {
            throw new RuntimeException(
                'Invalid Telegram command payload.'
            );
        }

        /*
         * Admin authorization.
         */
        $adminCommands = [
            'admin',
            'panels',
            'panel',
            'admin_callback', 'admin_toggle',
        ];

        if (in_array($command, $adminCommands, true)) {

            $userId = $payload['user_id'] ?? null;

            $chatId = $payload['chat_id'] ?? null;

            if (
                !is_int($userId) ||
                !is_int($chatId)
            ) {
                throw new RuntimeException(
                    'Invalid admin identity.'
                );
            }

            $authorized = (
                new TelegramAdminAccess($this->config)
            )->permits(
                $userId,
                $chatId
            );

            if (!$authorized) {
                throw new RuntimeException(
                    'Unauthorized admin payload.'
                );
            }

            /*
             * Validate panel command.
             */
            if ($command === 'panel') {

                if (
                    !is_string($payload['panel_id'] ?? null) ||
                    !preg_match(
                        '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',
                        $payload['panel_id']
                    )
                ) {
                    throw new RuntimeException(
                        'Invalid panel ID.'
                    );
                }

            } elseif (array_key_exists('panel_id', $payload)) {

                throw new RuntimeException(
                    'Unexpected panel ID.'
                );
            }

            /*
             * Validate inline callback payload.
             */
            // STAGE21C_TOGGLE_INBOX
            if ($command === 'admin_toggle') {

                $required = [
                    'update_id',
                    'chat_id',
                    'user_id',
                    'command',
                    'callback_data',
                    'callback_id',
                    'message_id',
                ];

                $actual = array_keys($payload);

                sort($required);
                sort($actual);

                if ($required !== $actual) {
                    throw new RuntimeException(
                        'Unexpected toggle payload fields.'
                    );
                }

                $data = $payload['callback_data'] ?? null;

                $callbackId = $payload['callback_id'] ?? null;

                $messageId = $payload['message_id'] ?? null;

                if (
                    !is_string($data)
                    || strlen($data) > 64
                    || preg_match(
                        '/^nr:t:(?:r:[01]:[a-f0-9]{20}|c:[01]:[a-f0-9]{20}:[a-f0-9]{8}:[a-f0-9]{20})$/D',
                        $data
                    ) !== 1
                    || !is_string($callbackId)
                    || strlen($callbackId) < 1
                    || strlen($callbackId) > 256
                    || preg_match(
                        '/[\x00-\x1F\x7F]/',
                        $callbackId
                    )
                    || !is_int($messageId)
                    || $messageId <= 0
                ) {
                    throw new RuntimeException(
                        'Invalid toggle callback payload.'
                    );
                }
            }

            if ($command === 'admin_callback') {

                $action = $payload['action'] ?? null;

                $callbackId = $payload['callback_id'] ?? null;

                $messageId = $payload['message_id'] ?? null;

                if (
                    !is_string($action) ||
                    !in_array(
                        $action,
                        [
                            'home',
                            'list',
                            'stats',
                            'add',
                            'help',
                            'panel',
                        ],
                        true
                    ) ||
                    !is_string($callbackId) ||
                    strlen($callbackId) < 1 ||
                    strlen($callbackId) > 256 ||
                    preg_match(
                        '/[\x00-\x1F\x7F]/',
                        $callbackId
                    ) ||
                    !is_int($messageId) ||
                    $messageId <= 0
                ) {
                    throw new RuntimeException(
                        'Invalid callback payload.'
                    );
                }

                /*
                 * Reconstruct the callback for validation.
                 */
                $wire = $action === 'panel'
                    ? 'nr:a:p:' . ($payload['panel_key'] ?? '')
                    : 'nr:a:' . $action;

                $parsed = AdminKeyboard::parseCallback(
                    $wire
                );

                if (
                    $parsed === null ||
                    $parsed['action'] !== $action
                ) {
                    throw new RuntimeException(
                        'Invalid callback action.'
                    );
                }

                if (
                    $action === 'panel' &&
                    ($parsed['panel_key'] ?? null)
                        !== ($payload['panel_key'] ?? null)
                ) {
                    throw new RuntimeException(
                        'Invalid panel callback key.'
                    );
                }

                if (
                    $action !== 'panel' &&
                    array_key_exists('panel_key', $payload)
                ) {
                    throw new RuntimeException(
                        'Unexpected panel key.'
                    );
                }

                /*
                 * Reject unknown callback fields.
                 */
                $allowedKeys = [
                    'update_id',
                    'chat_id',
                    'user_id',
                    'command',
                    'action',
                    'callback_id',
                    'message_id',
                    'panel_key',
                ];

                foreach (array_keys($payload) as $key) {

                    if (!in_array(
                        $key,
                        $allowedKeys,
                        true
                    )) {
                        throw new RuntimeException(
                            'Unexpected callback field.'
                        );
                    }
                }
            }
        }

        /*
         * Build Redis job.
         */
        $json = json_encode(
            [
                'id' => bin2hex(random_bytes(16)),
                'type' => 'telegram.command',
                'payload' => $payload,
                'attempts' => 0,
                'max_attempts' => 3,
                'created_at' => gmdate('c'),
            ],
            JSON_THROW_ON_ERROR |
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if (strlen($json) > 65536) {
            throw new RuntimeException(
                'Telegram job exceeds 64 KiB.'
            );
        }

        /*
         * Redis keys.
         */
        $queueKey = 'nrpro:queue:'
            . $this->queueName
            . ':pending';

        $updateKey = 'nrpro:telegram:updates:'
            . $this->queueName
            . ':'
            . $updateId;

        /*
         * Atomic deduplication and enqueue.
         */
        $lua = <<<'LUA'
if redis.call('SET', KEYS[1], '1', 'NX', 'EX', 172800) then
    redis.call('LPUSH', KEYS[2], ARGV[1])
    return 1
end
return 0
LUA;

        $result = $this->redis()->executeRaw([
            'EVAL',
            $lua,
            '2',
            $updateKey,
            $queueKey,
            $json,
        ]);

        if (!in_array(
            (int) $result,
            [0, 1],
            true
        )) {
            throw new RuntimeException(
                'Invalid Redis enqueue result.'
            );
        }

        return (int) $result === 1;
    }
}