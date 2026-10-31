<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn;

use NRPro\Pasargad\Modules\Vpn\Drivers\MarzbanDriver;
use NRPro\Pasargad\Modules\Vpn\Drivers\ThreeXuiDriver;
use NRPro\Pasargad\Modules\Vpn\Http\HttpTransportInterface;

/** Central registry. A separate PanelConnection is used for each remote panel. */
final class PanelDrivers
{
    public static function standard(?HttpTransportInterface $transport = null): PanelRegistry
    {
        $registry = new PanelRegistry();
        $registry->register('marzban', static fn (): MarzbanDriver => new MarzbanDriver($transport));
        $registry->register('xui3', static fn (): ThreeXuiDriver => new ThreeXuiDriver($transport));
        return $registry;
    }
}
