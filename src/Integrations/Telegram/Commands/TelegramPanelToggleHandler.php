<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Commands;

use InvalidArgumentException;
use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Modules\Vpn\Security\PanelToggleFlow;

final class TelegramPanelToggleHandler
{
    public function __construct(
        private readonly Config $config,
        private readonly PanelToggleFlow $flow
    ) {
    }

    public function render(
        array $job,
        callable $listPanels,
        callable $changeStatus,
        bool $writeEnabled = false
    ): array {

        $owner = $job['user_id'] ?? null;
        $chat = $job['chat_id'] ?? null;
        $message = $job['message_id'] ?? null;
        $update = $job['update_id'] ?? null;
        $callbackId = $job['callback_id'] ?? null;
        $data = $job['callback_data'] ?? null;

        // RECHECK ADMIN ACCESS

        if (
            ($job['command'] ?? null) !== 'admin_toggle'
            || !is_int($owner)
            || !is_int($chat)
            || !is_int($message)
            || $message <= 0
            || !is_int($update)
            || $update < 0
            || !is_string($callbackId)
            || strlen($callbackId) < 1
            || strlen($callbackId) > 256
            || preg_match('/[\x00-\x1F\x7F]/', $callbackId)
            || !is_string($data)
            || strlen($data) > 64
            || !(new TelegramAdminAccess($this->config))
                ->permits($owner, $chat)
        ) {
            throw new InvalidArgumentException(
                'Unauthorized or invalid panel toggle.'
            );
        }

        // REJECT UNEXPECTED FIELDS

        $required = [
            'update_id',
            'user_id',
            'chat_id',
            'command',
            'callback_id',
            'message_id',
            'callback_data',
        ];

        $keys = array_keys($job);

        sort($required);
        sort($keys);

        if ($keys !== $required) {
            throw new InvalidArgumentException(
                'Unexpected toggle fields.'
            );
        }

        // VALIDATE CALLBACK FORMAT

        $request = preg_match(
            '/^nr:t:r:([01]):([a-f0-9]{20})$/D',
            $data,
            $m
        ) === 1;

        $confirm = preg_match(
            '/^nr:t:c:([01]):([a-f0-9]{20}):([a-f0-9]{8}):([a-f0-9]{20})$/D',
            $data,
            $c
        ) === 1;

        if (!$request && !$confirm) {
            throw new InvalidArgumentException(
                'Invalid toggle callback format.'
            );
        }

        $key = $request ? $m[2] : $c[2];

        // SERVER-SIDE OWNER-SCOPED PANEL LOOKUP

        $rows = $listPanels($owner);

        if (!is_array($rows)) {
            throw new InvalidArgumentException(
                'Invalid panel list.'
            );
        }

        $matched = null;

        foreach ($rows as $row) {

            if (
                !is_array($row)
                || !is_string($row['panel_id'] ?? null)
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

            $rowKey = substr(
                hash('sha256', $id),
                0,
                20
            );

            if (hash_equals($rowKey, $key)) {

                if ($matched !== null) {
                    throw new InvalidArgumentException(
                        'Ambiguous panel key.'
                    );
                }

                $matched = $row;
            }
        }

        if ($matched === null) {

            return $this->reply(
                $chat,
                $message,
                $callbackId,
                '⛔ این پنل پیدا نشد یا به شما تعلق ندارد.',
                self::backKeyboard()
            );
        }

        $id = $matched['panel_id'];

        $raw = $matched['is_enabled'] ?? null;

        if (!in_array(
            $raw,
            [true, false, 't', 'f', '1', '0', 1, 0],
            true
        )) {
            throw new InvalidArgumentException(
                'Invalid panel state.'
            );
        }

        $enabled = in_array(
            $raw,
            [true, 't', '1', 1],
            true
        );

        // STEP 1: REQUEST CONFIRMATION

        if ($request) {

            $target = $this->flow->validateRequest(
                $data,
                $id,
                $enabled
            );

            if ($target === null) {

                return $this->reply(
                    $chat,
                    $message,
                    $callbackId,
                    '⚠️ وضعیت پنل تغییر کرده است؛ فهرست را دوباره باز کنید.',
                    $this->flow->panelActions($id, $enabled)
                );
            }

            $text = $target
                ? "⚠️ آیا فعال‌سازی این پنل را تأیید می‌کنید؟"
                : "⚠️ آیا غیرفعال‌سازی این پنل را تأیید می‌کنید؟";

            $text .= "\n⏳ اعتبار تأیید: ۳ دقیقه";

            $keyboard = $this->flow->confirmationKeyboard(
                $owner,
                $message,
                $id,
                $target
            );

            return $this->reply(
                $chat,
                $message,
                $callbackId,
                $text,
                $keyboard
            );
        }

        // STEP 2: VERIFY SIGNED CONFIRMATION

        $verified = $this->flow->verifyConfirmation(
            $data,
            $owner,
            $message,
            $id
        );

        if ($verified === null) {

            return $this->reply(
                $chat,
                $message,
                $callbackId,
                '⏰ تأیید نامعتبر یا منقضی شده است؛ دوباره درخواست بدهید.',
                $this->flow->panelActions($id, $enabled)
            );
        }

        // RECHECK EXPECTED STATUS

        if ($enabled !== $verified['expected_enabled']) {

            return $this->reply(
                $chat,
                $message,
                $callbackId,
                '⚠️ وضعیت پنل از زمان درخواست تغییر کرده است. تغییر جدیدی انجام نشد.',
                $this->flow->panelActions($id, $enabled)
            );
        }

        // EXPLICIT DATABASE WRITE GATE

        if (!$writeEnabled) {

            return $this->reply(
                $chat,
                $message,
                $callbackId,
                '🔒 آزمایش موفق بود؛ تغییر دیتابیس فعلاً غیرفعال است.',
                $this->flow->panelActions($id, $enabled)
            );
        }

        // CALL TRANSACTIONAL DATABASE SERVICE

        $result = $changeStatus(
            $owner,
            $id,
            $verified['expected_enabled'],
            $verified['target_enabled'],
            $verified['operation_id']
        );

        $text = match ($result) {

            'changed' =>
                '✅ وضعیت پنل در دیتابیس تغییر کرد و ثبت شد.',

            'duplicate' =>
                'ℹ️ این درخواست قبلاً انجام شده است.',

            'stale' =>
                '⚠️ وضعیت پنل تغییر کرده؛ درخواست قبلی اجرا نشد.',

            'not_found' =>
                '⛔ پنل دیگر در دسترس این مدیر نیست.',

            default => throw new InvalidArgumentException(
                'Unknown toggle result.'
            ),
        };

        $effective = $result === 'changed'
            ? $verified['target_enabled']
            : $enabled;

        return $this->reply(
            $chat,
            $message,
            $callbackId,
            $text,
            $this->flow->panelActions($id, $effective)
        );
    }

    private static function backKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🔙 فهرست پنل‌ها',
                        'callback_data' => 'nr:a:list',
                    ],
                ],
            ],
        ];
    }

    private function reply(
        int $chat,
        int $message,
        string $callbackId,
        string $text,
        array $keyboard
    ): array {
        return [
            'chat_id' => $chat,
            'message_id' => $message,
            'callback_id' => $callbackId,
            'text' => $text,
            'reply_markup' => $keyboard,
        ];
    }
}