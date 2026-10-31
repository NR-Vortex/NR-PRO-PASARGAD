<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn;

final class PanelCapability
{
    public const PING = 'ping';
    public const READ_ACCOUNT = 'read_account';
    public const CREATE_ACCOUNT = 'create_account';
    public const UPDATE_ACCOUNT = 'update_account';
    public const DELETE_ACCOUNT = 'delete_account';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PING, self::READ_ACCOUNT, self::CREATE_ACCOUNT, self::UPDATE_ACCOUNT, self::DELETE_ACCOUNT];
    }
}
