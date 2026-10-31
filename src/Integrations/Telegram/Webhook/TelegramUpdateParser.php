<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Webhook;

/**
 * Pure parser: no polling, no webhook route, no writes or external requests.
 * Unrecognized updates return null. Verify webhook authentication separately.
 */
final class TelegramUpdateParser
{
    /**
     * @param array<string, mixed> $update
     * @return array{update_id:int, chat_id:int, user_id:int, command:string, argument:string}|null
     */
    public function parseCommand(array $update): ?array
    {
        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $text = $message['text'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $userId = $message['from']['id'] ?? null;
        $updateId = $update['update_id'] ?? null;

        if (!is_string($text) || !is_int($chatId) || !is_int($userId) || !is_int($updateId)) {
            return null;
        }

        if (!preg_match('/^\/([A-Za-z][A-Za-z0-9_]{0,31})(?:@[A-Za-z0-9_]{5,32})?(?:\s+([\s\S]*))?$/D', $text, $match)) {
            return null;
        }

        return [
            'update_id' => $updateId,
            'chat_id' => $chatId,
            'user_id' => $userId,
            'command' => strtolower($match[1]),
            'argument' => trim($match[2] ?? ''),
        ];
    }
}
