<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Security;

use InvalidArgumentException;

/**
 * Two-step panel status confirmation.
 *
 * This class does NOT change database records.
 * The Worker must verify administrator access
 * and owner-scoped panel metadata before use.
 */
final class PanelToggleFlow
{
    private const TTL_SECONDS = 180;

    private readonly string $macKey;

    public function __construct(
        string $panelEncryptionSecret
    ) {
        if (strlen($panelEncryptionSecret) < 32) {
            throw new InvalidArgumentException(
                'Panel encryption secret is missing or too short.'
            );
        }

        // Derive a separate signing key.
        $this->macKey = hash_hkdf(
            'sha256',
            $panelEncryptionSecret,
            32,
            'nrpro.telegram.panel-toggle.confirm.v1'
        );
    }

    /**
     * Show an activate/deactivate request button.
     */
    public function panelActions(
        string $panelId,
        bool $enabled
    ): array {
        $key = self::panelKey($panelId);

        $target = $enabled ? '0' : '1';

        $label = $enabled
            ? '⛔ درخواست غیرفعال‌سازی'
            : '✅ درخواست فعال‌سازی';

        return self::keyboard([
            [
                [
                    'text' => $label,
                    'callback_data' =>
                        'nr:t:r:' . $target . ':' . $key,
                ],
            ],
            [
                [
                    'text' => '🔌 تست اتصال مجدد',
                    'callback_data' =>
                        'nr:a:p:' . $key,
                ],
            ],
            [
                [
                    'text' => '🔙 فهرست',
                    'callback_data' => 'nr:a:list',
                ],
            ],
        ]);
    }

    /**
     * Validate a toggle request against the
     * fresh current status of an owned panel.
     *
     * Returns target status or null.
     */
    public function validateRequest(
        string $data,
        string $panelId,
        bool $currentEnabled
    ): ?bool {
        if (!preg_match(
            '/^nr:t:r:([01]):([a-f0-9]{20})$/D',
            $data,
            $matches
        )) {
            return null;
        }

        if (!hash_equals(
            self::panelKey($panelId),
            $matches[2]
        )) {
            return null;
        }

        $target = $matches[1] === '1';

        // Do not request an unnecessary change.
        if ($target === $currentEnabled) {
            return null;
        }

        return $target;
    }

    /**
     * Generate a short-lived signed confirmation.
     */
    public function confirmationKeyboard(
        int $ownerId,
        int $messageId,
        string $panelId,
        bool $targetEnabled,
        ?int $now = null
    ): array {
        self::checkContext(
            $ownerId,
            $messageId,
            $panelId
        );

        $now ??= time();

        if ($now <= 0 || $now > 0xffffffff) {
            throw new InvalidArgumentException(
                'Invalid issue timestamp.'
            );
        }

        $key = self::panelKey($panelId);

        $target = $targetEnabled ? '1' : '0';

        $timeHex = sprintf('%08x', $now);

        $mac = $this->mac(
            $ownerId,
            $messageId,
            $panelId,
            $target,
            $timeHex
        );

        $token = 'nr:t:c:'
            . $target
            . ':'
            . $key
            . ':'
            . $timeHex
            . ':'
            . $mac;

        return self::keyboard([
            [
                [
                    'text' => '✅ تأیید نهایی',
                    'callback_data' => $token,
                ],
            ],
            [
                [
                    'text' => '❌ انصراف',
                    'callback_data' =>
                        'nr:a:p:' . $key,
                ],
            ],
        ]);
    }

    /**
     * Verify signed confirmation.
     *
     * Returns:
     * - expected_enabled
     * - target_enabled
     * - operation_id
     *
     * Returns null on invalid/expired tokens.
     */
    public function verifyConfirmation(
        string $token,
        int $ownerId,
        int $messageId,
        string $panelId,
        ?int $now = null
    ): ?array {
        if (
            $ownerId <= 0 ||
            $messageId <= 0 ||
            !self::validPanelId($panelId)
        ) {
            return null;
        }

        if (!preg_match(
            '/^nr:t:c:([01]):([a-f0-9]{20}):([a-f0-9]{8}):([a-f0-9]{20})$/D',
            $token,
            $matches
        )) {
            return null;
        }

        if (!hash_equals(
            self::panelKey($panelId),
            $matches[2]
        )) {
            return null;
        }

        $now ??= time();

        $issued = hexdec($matches[3]);

        // Reject expired and future-dated tokens.
        if (
            $issued > $now + 15 ||
            $now > $issued + self::TTL_SECONDS
        ) {
            return null;
        }

        $expectedMac = $this->mac(
            $ownerId,
            $messageId,
            $panelId,
            $matches[1],
            $matches[3]
        );

        if (!hash_equals(
            $expectedMac,
            $matches[4]
        )) {
            return null;
        }

        $target = $matches[1] === '1';

        // Stable operation ID for PostgreSQL deduplication.
        $operationId = substr(
            hash(
                'sha256',
                'nrpro:toggle:'
                . $ownerId
                . ':'
                . $token
            ),
            0,
            32
        );

        return [
            'expected_enabled' => !$target,
            'target_enabled' => $target,
            'operation_id' => $operationId,
        ];
    }

    /**
     * Calculate context-bound authentication tag.
     */
    private function mac(
        int $ownerId,
        int $messageId,
        string $panelId,
        string $target,
        string $timeHex
    ): string {
        $data = implode('|', [
            'nrpro-toggle-v1',
            $ownerId,
            $messageId,
            $panelId,
            $target,
            $timeHex,
        ]);

        return substr(
            hash_hmac(
                'sha256',
                $data,
                $this->macKey
            ),
            0,
            20
        );
    }

    /**
     * Opaque panel identifier.
     */
    private static function panelKey(
        string $panelId
    ): string {
        if (!self::validPanelId($panelId)) {
            throw new InvalidArgumentException(
                'Invalid panel identifier.'
            );
        }

        return substr(
            hash('sha256', $panelId),
            0,
            20
        );
    }

    private static function validPanelId(
        string $panelId
    ): bool {
        return preg_match(
            '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',
            $panelId
        ) === 1;
    }

    private static function checkContext(
        int $ownerId,
        int $messageId,
        string $panelId
    ): void {
        if (
            $ownerId <= 0 ||
            $messageId <= 0 ||
            !self::validPanelId($panelId)
        ) {
            throw new InvalidArgumentException(
                'Invalid confirmation context.'
            );
        }
    }

    /**
     * Validate Telegram callback length.
     */
    private static function keyboard(
        array $rows
    ): array {
        foreach ($rows as $row) {
            foreach ($row as $button) {
                if (
                    strlen(
                        $button['callback_data']
                    ) > 64
                ) {
                    throw new InvalidArgumentException(
                        'Telegram callback_data too long.'
                    );
                }
            }
        }

        return [
            'inline_keyboard' => $rows,
        ];
    }
}