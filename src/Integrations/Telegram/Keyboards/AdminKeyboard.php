<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Keyboards;

final class AdminKeyboard
{
    /**
     * Main admin dashboard.
     */
    public static function dashboard(): array
    {
        return self::rows([
            [
                self::button(
                    '🖥 مدیریت پنل‌ها',
                    'nr:a:list'
                ),
                self::button(
                    '📊 آمار',
                    'nr:a:stats'
                ),
            ],
            [
                self::button(
                    '➕ افزودن پنل',
                    'nr:a:add'
                ),
                self::button(
                    '❓ راهنما',
                    'nr:a:help'
                ),
            ],
        ]);
    }

    /**
     * List registered VPN panels.
     */
    public static function panelList(array $panels): array
    {
        $rows = [];

        $count = 0;

        foreach ($panels as $panel) {

            if (
                !is_array($panel) ||
                !is_string($panel['panel_id'] ?? null)
            ) {
                continue;
            }

            $id = $panel['panel_id'];

            if (
                !preg_match(
                    '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',
                    $id
                )
            ) {
                continue;
            }

            $name = self::label(
                (string) ($panel['display_name'] ?? $id)
            );

            $enabled = in_array(
                $panel['is_enabled'] ?? false,
                [true, 't', '1', 1],
                true
            );

            $status = $enabled ? '🟢 ' : '⚪ ';

            $rows[] = [
                self::button(
                    $status . $name,
                    'nr:a:p:' . self::panelKey($id)
                ),
            ];

            $count++;

            if ($count >= 10) {
                break;
            }
        }

        $rows[] = [
            self::button(
                '🔄 تازه‌سازی',
                'nr:a:list'
            ),
            self::button(
                '🏠 بازگشت',
                'nr:a:home'
            ),
        ];

        return self::rows($rows);
    }

    /**
     * Individual panel keyboard.
     */
    public static function panelDetails(?string $panelId = null): array
    {
        $rows = [];

        if ($panelId !== null) {
            $rows[] = [
                self::button(
                    '🔌 تست اتصال مجدد',
                    'nr:a:p:' . self::panelKey($panelId)
                ),
            ];
        }

        $rows[] = [
            self::button(
                '🔙 فهرست پنل‌ها',
                'nr:a:list'
            ),
        ];

        $rows[] = [
            self::button(
                '🏠 داشبورد',
                'nr:a:home'
            ),
        ];

        return self::rows($rows);
    }

    /**
     * General back button.
     */
    public static function back(): array
    {
        return self::rows([
            [
                self::button(
                    '🏠 بازگشت به مدیریت',
                    'nr:a:home'
                ),
            ],
        ]);
    }

    /**
     * Validate and parse callback data.
     */
    public static function parseCallback(
        string $data
    ): ?array {

        if (strlen($data) > 64) {
            return null;
        }

        $actions = [
            'nr:a:home'  => 'home',
            'nr:a:list'  => 'list',
            'nr:a:stats' => 'stats',
            'nr:a:add'   => 'add',
            'nr:a:help'  => 'help',
        ];

        if (isset($actions[$data])) {

            return [
                'action' => $actions[$data],
            ];
        }

        if (
            preg_match(
                '/^nr:a:p:([a-f0-9]{20})$/D',
                $data,
                $matches
            ) === 1
        ) {

            return [
                'action'    => 'panel',
                'panel_key' => $matches[1],
            ];
        }

        return null;
    }

    /**
     * Generate stable opaque panel selector.
     */
    public static function panelKey(
        string $panelId
    ): string {

        if (
            !preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',
                $panelId
            )
        ) {
            throw new \InvalidArgumentException(
                'Invalid panel identifier.'
            );
        }

        return substr(
            hash('sha256', $panelId),
            0,
            20
        );
    }

    /**
     * Sanitize Telegram button labels.
     */
    private static function label(
        string $text
    ): string {

        $text = preg_replace(
            '/[\p{C}]/u',
            ' ',
            $text
        ) ?? '';

        $text = trim($text);

        $characters = preg_split(
            '//u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (!is_array($characters)) {
            return 'پنل';
        }

        $short = implode(
            '',
            array_slice($characters, 0, 30)
        );

        if ($short === '') {
            $short = 'پنل';
        }

        if (count($characters) > 30) {
            $short .= '…';
        }

        return $short;
    }

    /**
     * Create an inline button.
     */
    private static function button(
        string $text,
        string $data
    ): array {

        if (
            strlen($data) < 1 ||
            strlen($data) > 64
        ) {
            throw new \LogicException(
                'Invalid callback data length.'
            );
        }

        return [
            'text'          => $text,
            'callback_data' => $data,
        ];
    }

    /**
     * Create Telegram inline keyboard markup.
     */
    private static function rows(
        array $rows
    ): array {

        return [
            'inline_keyboard' => $rows,
        ];
    }
}