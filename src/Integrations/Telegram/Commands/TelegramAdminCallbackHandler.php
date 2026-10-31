<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Commands;

use InvalidArgumentException;
use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Integrations\Telegram\Keyboards\AdminKeyboard;

final class TelegramAdminCallbackHandler
{
    public function __construct(
        private readonly Config $config
    ) {
    }

    public function render(
        array $payload,
        callable $listPanels,
        ?callable $checkConnection = null
    ): array {

        $user = $payload['user_id'] ?? null;
        $chat = $payload['chat_id'] ?? null;
        $update = $payload['update_id'] ?? null;
        $message = $payload['message_id'] ?? null;
        $callback = $payload['callback_id'] ?? null;
        $action = $payload['action'] ?? null;

        // SECURITY: RECHECK ADMIN ACCESS

        if (
            ($payload['command'] ?? null) !== 'admin_callback'
            || !is_int($user)
            || !is_int($chat)
            || !is_int($update)
            || $update < 0
            || !is_int($message)
            || $message <= 0
            || !is_string($callback)
            || strlen($callback) < 1
            || strlen($callback) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $callback)
            || !is_string($action)
            || !(new TelegramAdminAccess($this->config))
                ->permits($user, $chat)
        ) {
            throw new InvalidArgumentException(
                'Unauthorized admin callback.'
            );
        }

        // VALIDATE CALLBACK

        $panelKey = $payload['panel_key'] ?? null;

        $wire = $action === 'panel'
            ? (
                is_string($panelKey)
                    ? 'nr:a:p:' . $panelKey
                    : ''
            )
            : 'nr:a:' . $action;

        $parsed = AdminKeyboard::parseCallback($wire);

        if (
            $parsed === null
            || $parsed['action'] !== $action
            || (
                $action !== 'panel'
                && array_key_exists('panel_key', $payload)
            )
        ) {
            throw new InvalidArgumentException(
                'Invalid callback action.'
            );
        }

        $allowed = [
            'update_id',
            'chat_id',
            'user_id',
            'command',
            'action',
            'callback_id',
            'message_id',
        ];

        if ($action === 'panel') {
            $allowed[] = 'panel_key';
        }

        foreach (array_keys($payload) as $key) {

            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(
                    'Unexpected callback field.'
                );
            }
        }

        $keyboard = AdminKeyboard::back();
        $text = '';

        // MAIN DASHBOARD

        if ($action === 'home') {

            $text = "🛡 مدیریت NR PRO BOT\n\n"
                . "از دکمه‌های زیر استفاده کنید.";

            $keyboard = AdminKeyboard::dashboard();

        // HELP

        } elseif ($action === 'help') {

            $text = "❓ راهنمای مدیریت\n\n"
                . "/admin — داشبورد\n"
                . "/panels — فهرست پنل‌ها\n"
                . "/panel PANEL_ID — جزئیات پنل\n\n"
                . "رمز یا توکن پنل را در تلگرام نفرستید.";

        // ADD PANEL

        } elseif ($action === 'add') {

            $text = "➕ افزودن پنل\n\n"
                . "در این نسخه ثبت پنل از تلگرام "
                . "غیرفعال است.\n"
                . "از ابزار امن محلی Stage 20 استفاده کنید.";

        // PANEL OPERATIONS

        } elseif (
            in_array(
                $action,
                ['list', 'stats', 'panel'],
                true
            )
        ) {

            $rows = $listPanels($user);

            if (!is_array($rows)) {
                throw new InvalidArgumentException(
                    'Invalid panel metadata.'
                );
            }

            // PANEL LIST

            if ($action === 'list') {

                $text = $rows === []
                    ? "📂 هنوز پنلی ثبت نشده است."
                    : "🖥 پنل‌های ثبت‌شده\n\n"
                        . "برای مشاهده جزئیات و بررسی اتصال "
                        . "روی نام پنل بزنید.\n"
                        . "حداکثر ۱۰ پنل نمایش داده می‌شود.";

                $keyboard = AdminKeyboard::panelList($rows);

            // STATS

            } elseif ($action === 'stats') {

                $total = 0;
                $enabled = 0;

                foreach ($rows as $row) {

                    if (
                        !is_array($row)
                        || !is_string(
                            $row['panel_id'] ?? null
                        )
                    ) {
                        continue;
                    }

                    $total++;

                    if (self::enabled(
                        $row['is_enabled'] ?? false
                    )) {
                        $enabled++;
                    }
                }

                $disabled = $total - $enabled;

                $text = "📊 آمار اتصالات پنل\n\n"
                    . "کل: {$total}\n"
                    . "فعال: {$enabled}\n"
                    . "غیرفعال: {$disabled}\n\n"
                    . "این آمار تعداد اتصال‌هاست، "
                    . "نه تعداد کاربران VPN.";

            // PANEL DETAILS + CONNECTION CHECK

            } else {

                $text = 'پنل موردنظر برای این مدیر پیدا نشد.';

                $keyboard = AdminKeyboard::panelDetails();

                foreach ($rows as $row) {

                    if (
                        !is_array($row)
                        || !is_string(
                            $row['panel_id'] ?? null
                        )
                    ) {
                        continue;
                    }

                    $id = $row['panel_id'];

                    if (!preg_match(
                        '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',
                        $id
                    )) {
                        continue;
                    }

                    if (!hash_equals(
                        AdminKeyboard::panelKey($id),
                        $parsed['panel_key']
                    )) {
                        continue;
                    }

                    $keyboard = AdminKeyboard::panelDetails($id);
                    $name = self::safe(
                        (string) (
                            $row['display_name'] ?? 'پنل'
                        )
                    );

                    $type = self::safe(
                        (string) (
                            $row['driver_type'] ?? 'نامشخص'
                        )
                    );

                    $active = self::enabled(
                        $row['is_enabled'] ?? false
                    );

                    $state = $active
                        ? 'فعال ✅'
                        : 'غیرفعال ⏸';

                    $text = "🖥 جزئیات پنل\n\n"
                        . "نام: {$name}\n"
                        . "شناسه: {$id}\n"
                        . "نوع: {$type}\n"
                        . "وضعیت: {$state}";

                    // NEVER PING DISABLED PANELS

                    if (!$active) {

                        $text .= "\n\n🔌 اتصال: غیرفعال (بررسی نشد)";

                    } elseif ($checkConnection !== null) {

                        // Panel ID comes from the owner-scoped
                        // database result, not from arbitrary text.

                        try {

                            $health = $checkConnection(
                                $user,
                                $id
                            );

                            $status = match ($health) {

                                'online' =>
                                    '🟢 در دسترس و احراز هویت موفق',

                                'auth_failed' =>
                                    '🟠 مشکل احراز هویت',

                                'unavailable' =>
                                    '🔴 پنل در دسترس نیست',

                                default =>
                                    '🟡 وضعیت نامشخص',
                            };

                        } catch (\Throwable) {

                            // Never reveal remote API errors.

                            $status = '🟡 وضعیت نامشخص';
                        }

                        $text .= "\n\n🔌 اتصال: " . $status;

                    } else {

                        $text .= "\n\n🔌 تست اتصال هنوز فعال نشده";
                    }

                    break;
                }
            }

        } else {

            throw new InvalidArgumentException(
                'Unknown admin action.'
            );
        }

        // STAGE21C_REFRESH_MARKER
        // Refresh text changes on every panel check.
        if ($action === 'panel') {
            $text .= "\n\n🕒 بررسی مجدد: "
                . gmdate('H:i:s')
                . '-'
                . bin2hex(random_bytes(4));
        }

        return [
            'chat_id' => $chat,
            'message_id' => $message,
            'callback_id' => $callback,
            'text' => $text,
            'reply_markup' => $keyboard,
        ];
    }

    private static function enabled(mixed $value): bool
    {
        return in_array(
            $value,
            [true, 't', '1', 1],
            true
        );
    }

    private static function safe(string $text): string
    {
        $clean = preg_replace(
            '/[\p{C}]/u',
            ' ',
            $text
        ) ?? '';

        $characters = preg_split(
            '//u',
            trim($clean),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (
            !is_array($characters)
            || $characters === []
        ) {
            return 'نامشخص';
        }

        return implode(
            '',
            array_slice($characters, 0, 80)
        ) . (
            count($characters) > 80 ? '…' : ''
        );
    }
}