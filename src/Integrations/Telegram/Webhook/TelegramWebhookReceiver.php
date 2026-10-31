<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Webhook;

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramAdminAccess;
use NRPro\Pasargad\Integrations\Telegram\Keyboards\AdminKeyboard;
use Throwable;

final class TelegramWebhookReceiver
{
    public function __construct(
        private readonly Config $config,
        private readonly TelegramUpdateParser $parser,
        private readonly TelegramWebhookStore $store,
    ) {
    }

    public function receive(
        string $method,
        string $contentType,
        string $providedSecret,
        string $body
    ): array {

        if ($method !== 'POST') {
            return [
                'status' => 405,
                'code' => 'method_not_allowed',
            ];
        }

        $secret = (string) $this->config->get(
            'telegram.webhook_secret',
            ''
        );

        if (!preg_match(
            '/^[A-Za-z0-9_-]{32,256}$/D',
            $secret
        )) {
            return [
                'status' => 503,
                'code' => 'not_configured',
            ];
        }

        if (!hash_equals($secret, $providedSecret)) {
            return [
                'status' => 403,
                'code' => 'forbidden',
            ];
        }

        if (!preg_match(
            '~^application/json\s*(?:;.*)?$~i',
            trim($contentType)
        )) {
            return [
                'status' => 415,
                'code' => 'unsupported_media_type',
            ];
        }

        if ($body === '' || strlen($body) > 65536) {
            return [
                'status' => 413,
                'code' => 'invalid_body_size',
            ];
        }

        try {

            $update = json_decode(
                $body,
                true,
                16,
                JSON_THROW_ON_ERROR
            );

        } catch (\JsonException) {

            return [
                'status' => 400,
                'code' => 'invalid_json',
            ];
        }

        if (
            !is_array($update) ||
            !is_int($update['update_id'] ?? null) ||
            $update['update_id'] < 0
        ) {
            return [
                'status' => 400,
                'code' => 'invalid_update',
            ];
        }

        /*
         * Handle inline keyboard callbacks.
         */
        $payload = $this->callbackPayload($update);

        if ($payload === null) {

            if (array_key_exists('callback_query', $update)) {
                return [
                    'status' => 200,
                    'code' => 'ignored',
                ];
            }

            /*
             * Handle regular commands.
             */
            $parsed = $this->parser->parseCommand($update);

            if (
                $parsed === null ||
                !in_array(
                    $parsed['command'],
                    [
                        'start',
                        'help',
                        'admin',
                        'panels',
                        'panel',
                    ],
                    true
                )
            ) {
                return [
                    'status' => 200,
                    'code' => 'ignored',
                ];
            }

            /*
             * Reject commands addressed to another bot.
             */
            $text = $update['message']['text'] ?? '';

            if (
                is_string($text) &&
                preg_match('/^\/[a-z][a-z0-9_]*@/i', $text)
            ) {
                return [
                    'status' => 200,
                    'code' => 'ignored_mention',
                ];
            }

            /*
             * Admin authorization.
             */
            $adminCommand = in_array(
                $parsed['command'],
                ['admin', 'panels', 'panel'],
                true
            );

            if ($adminCommand) {

                $chatType = $update['message']['chat']['type']
                    ?? null;

                $authorized = (
                    new TelegramAdminAccess($this->config)
                )->permits(
                    $parsed['user_id'],
                    $parsed['chat_id']
                );

                if (
                    $chatType !== 'private' ||
                    !$authorized
                ) {
                    return [
                        'status' => 200,
                        'code' => 'ignored',
                    ];
                }

                if ($parsed['command'] === 'panel') {

                    if (!preg_match(
                        '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',
                        $parsed['argument']
                    )) {
                        return [
                            'status' => 200,
                            'code' => 'ignored',
                        ];
                    }

                } elseif ($parsed['argument'] !== '') {

                    return [
                        'status' => 200,
                        'code' => 'ignored',
                    ];
                }
            }

            $payload = [
                'update_id' => $parsed['update_id'],
                'chat_id' => $parsed['chat_id'],
                'user_id' => $parsed['user_id'],
                'command' => $parsed['command'],
            ];

            if ($parsed['command'] === 'panel') {
                $payload['panel_id'] = $parsed['argument'];
            }
        }

        /*
         * Store only validated data.
         */
        try {

            $fresh = $this->store->enqueueOnce(
                $update['update_id'],
                $payload
            );

        } catch (Throwable) {

            return [
                'status' => 503,
                'code' => 'temporarily_unavailable',
            ];
        }

        return [
            'status' => 200,
            'code' => $fresh ? 'queued' : 'duplicate',
        ];
    }

    /**
     * Validate Telegram callback query.
     */
    private function callbackPayload(array $update): ?array
    {
        $callback = $update['callback_query'] ?? null;

        if (!is_array($callback)) {
            return null;
        }

        $id = $callback['id'] ?? null;

        $data = $callback['data'] ?? null;

        $userId = $callback['from']['id'] ?? null;

        $chat = $callback['message']['chat'] ?? null;

        $messageId = $callback['message']['message_id']
            ?? null;

        /*
         * Validate callback metadata.
         */
        if (
            !is_string($id) ||
            strlen($id) < 1 ||
            strlen($id) > 256 ||
            preg_match('/[\x00-\x1F\x7F]/', $id) ||
            !is_string($data) ||
            strlen($data) > 64 ||
            !is_int($userId) ||
            !is_array($chat) ||
            !is_int($chat['id'] ?? null) ||
            ($chat['type'] ?? null) !== 'private' ||
            !is_int($messageId) ||
            $messageId <= 0
        ) {
            return null;
        }

        /*
         * Verify that the callback belongs
         * to the configured administrator.
         */
        $authorized = (
            new TelegramAdminAccess($this->config)
        )->permits(
            $userId,
            $chat['id']
        );

        if (!$authorized) {
            return null;
        }

        /*
         * Parse approved admin keyboard actions.
         */
        // STAGE21C_TOGGLE_WEBHOOK
        // Only authenticated private-admin callbacks reach this point.
        if (preg_match(
            '/^nr:t:(?:r:[01]:[a-f0-9]{20}|c:[01]:[a-f0-9]{20}:[a-f0-9]{8}:[a-f0-9]{20})$/D',
            $data
        ) === 1) {
            return [
                'update_id' => $update['update_id'],
                'chat_id' => $chat['id'],
                'user_id' => $userId,
                'command' => 'admin_toggle',
                'callback_data' => $data,
                'callback_id' => $id,
                'message_id' => $messageId,
            ];
        }

        $selected = AdminKeyboard::parseCallback($data);

        if ($selected === null) {
            return null;
        }

        /*
         * Create sanitized queue payload.
         */
        $payload = [
            'update_id' => $update['update_id'],
            'chat_id' => $chat['id'],
            'user_id' => $userId,
            'command' => 'admin_callback',
            'action' => $selected['action'],
            'callback_id' => $id,
            'message_id' => $messageId,
        ];

        if ($selected['action'] === 'panel') {

            $payload['panel_key'] =
                $selected['panel_key'];
        }

        return $payload;
    }
}