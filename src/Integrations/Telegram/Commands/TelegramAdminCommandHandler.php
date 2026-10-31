<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Commands;

use InvalidArgumentException;
use NRPro\Pasargad\Core\Config\Config;

/**
 * Read-only Telegram administration. Never exposes stored credentials or panel URLs.
 * Metadata is retrieved only after authorization in the worker, from the caller's owner scope.
 */
final class TelegramAdminCommandHandler
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @param callable(int):array $listMetadata trusted backend metadata supplier
     * @return array{chat_id:int,text:string}
     */
    public function render(array $payload, callable $listMetadata): array
    {
        $userId = $payload['user_id'] ?? null;
        $chatId = $payload['chat_id'] ?? null;
        $command = $payload['command'] ?? null;
        $updateId = $payload['update_id'] ?? null;
        if (!is_int($userId) || !is_int($chatId) || !is_int($updateId) || $updateId < 0
            || !(new TelegramAdminAccess($this->config))->permits($userId, $chatId)
            || !is_string($command) || !in_array($command, ['admin', 'panels', 'panel'], true)) {
            throw new InvalidArgumentException('Unauthorized admin command.');
        }

        if ($command === 'admin') {
            return ['chat_id' => $chatId, 'text' => "🛡 مدیریت NR PRO BOT\n\n/panels — فهرست پنل‌ها\n/panel PANEL_ID — جزئیات پنل\n\nاین بخش فقط خواندنی است. افزودن پنل فعلاً از ابزار امن محلی انجام می‌شود. رمز و توکن را در تلگرام نفرستید."];
        }

        $panelId = $payload['panel_id'] ?? null;
        if ($command === 'panel' && (!is_string($panelId) || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/iD', $panelId))) {
            throw new InvalidArgumentException('Invalid panel ID.');
        }
        if ($command === 'panels' && $panelId !== null) {
            throw new InvalidArgumentException('Unexpected panel ID.');
        }

        $rows = $listMetadata($userId);
        if (!is_array($rows)) {
            throw new InvalidArgumentException('Invalid panel metadata.');
        }

        if ($command === 'panel') {
            foreach ($rows as $row) {
                if (!is_array($row) || !is_string($row['panel_id'] ?? null) || $row['panel_id'] !== $panelId) {
                    continue;
                }
                return [
                    'chat_id' => $chatId,
                    'text' => "📋 اطلاعات پنل\nشناسه: " . self::safe($row['panel_id'], 64)
                        . "\nنام: " . self::safe((string) ($row['display_name'] ?? ''), 120)
                        . "\nنوع: " . self::safe((string) ($row['driver_type'] ?? ''), 50)
                        . "\nوضعیت: " . (self::enabled($row['is_enabled'] ?? false) ? 'فعال ✅' : 'غیرفعال ⏸')
                        . "\n\nهیچ اطلاعات محرمانه‌ای نمایش داده نمی‌شود.",
                ];
            }
            return ['chat_id' => $chatId, 'text' => 'پنلی با این شناسه برای شما پیدا نشد.'];
        }

        if ($rows === []) {
            return ['chat_id' => $chatId, 'text' => "📂 هنوز پنلی ثبت نشده است.\nبرای ثبت امن پنل، از ابزار محلی Stage 20 استفاده کنید."];
        }
        $messages = ['📂 پنل‌های VPN شما (حداکثر ۱۵ مورد):'];
        $shown = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['panel_id'] ?? null)) {
                continue;
            }
            if ($shown++ === 15) {
                break;
            }
            $messages[] = ($shown) . '. ' . self::safe((string) ($row['display_name'] ?? ''), 120)
                . ' [' . self::safe((string) ($row['driver_type'] ?? ''), 50) . '] '
                . (self::enabled($row['is_enabled'] ?? false) ? '✅' : '⏸')
                . "\nشناسه: " . self::safe($row['panel_id'], 64);
        }
        $messages[] = "برای جزئیات: /panel PANEL_ID\nتوکن و رمز پنل نمایش داده نمی‌شود.";
        return ['chat_id' => $chatId, 'text' => implode("\n\n", $messages)];
    }

    private static function enabled(mixed $value): bool
    {
        // PDO pgsql can return PostgreSQL boolean as true/false or 't'/'f'.
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }

    private static function safe(string $value, int $limit): string
    {
        // Strip all controls and line breaks, keeping readable Persian/Unicode names.
        $value = preg_replace('/[\p{C}]/u', ' ', $value) ?? '';
        $value = trim($value);
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            return '';
        }
        if (count($characters) > $limit) {
            return implode('', array_slice($characters, 0, $limit)) . '…';
        }
        return $value;
    }
}
