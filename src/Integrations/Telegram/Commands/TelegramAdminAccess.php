<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Integrations\Telegram\Commands;

use NRPro\Pasargad\Core\Config\Config;

/** A fail-closed, single-admin policy. Expand to a DB-backed RBAC policy later. */
final class TelegramAdminAccess
{
    public function __construct(private readonly Config $config)
    {
    }

    public function permits(int $userId, int $chatId): bool
    {
        $configured = (string) $this->config->get('telegram.admin_id', '');
        if ($userId <= 0 || $chatId !== $userId || !preg_match('/^[1-9][0-9]{0,18}$/D', $configured)) {
            return false;
        }
        return (string) $userId === $configured;
    }
}
