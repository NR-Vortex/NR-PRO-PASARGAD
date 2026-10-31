<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Commands;

use InvalidArgumentException;

/**
 * Pure command renderer. No network calls, secrets, DB writes or Redis IO.
 * The webhook stores only IDs and a supported command name.
 */
final class TelegramCommandHandler
{
    /**
     * @param array<string, mixed> $payload
     * @return array{chat_id:int,text:string}
     */
    public function render(array $payload): array
    {
        $updateId = $payload['update_id'] ?? null;
        $chatId = $payload['chat_id'] ?? null;
        $userId = $payload['user_id'] ?? null;
        $command = $payload['command'] ?? null;

        if (!is_int($updateId) || $updateId < 0
            || !is_int($chatId) || $chatId === 0
            || !is_int($userId) || $userId <= 0
            || !is_string($command)
            || !in_array($command, ['start', 'help'], true)
        ) {
            throw new InvalidArgumentException('Invalid Telegram command job payload.');
        }

        $text = match ($command) {
            'start' => "سلام 👋\nبه ربات NR PRO PASARGAD خوش آمدید.\n\nبرای مشاهده راهنما /help را ارسال کنید.\n\nامکانات خرید و مدیریت اشتراک هنوز در حال توسعه‌اند.",
            'help' => "راهنمای NR PRO PASARGAD 🤖\n\n/start — پیام خوشامدگویی\n/help — نمایش راهنما\n\nسایر امکانات به‌تدریج اضافه می‌شوند.",
        };

        return [
            'chat_id' => $chatId,
            'text' => $text,
        ];
    }
}
