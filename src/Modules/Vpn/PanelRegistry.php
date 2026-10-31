<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn;

use InvalidArgumentException;
use NRPro\Pasargad\Modules\Vpn\Contracts\PanelDriverInterface;
use RuntimeException;

/** Driver factories are per-type; multiple connections may share one driver type. */
final class PanelRegistry
{
    /** @var array<string, \Closure():PanelDriverInterface> */
    private array $factories = [];

    /** @param \Closure():PanelDriverInterface $factory */
    public function register(string $type, \Closure $factory): void
    {
        if (!preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $type)) {
            throw new InvalidArgumentException('Invalid driver type.');
        }
        if (array_key_exists($type, $this->factories)) {
            throw new RuntimeException('Panel driver already registered: ' . $type);
        }
        $this->factories[$type] = $factory;
    }

    /** @return list<string> */
    public function types(): array
    {
        return array_keys($this->factories);
    }

    public function driver(string $type): PanelDriverInterface
    {
        if (!isset($this->factories[$type])) {
            throw new InvalidArgumentException('Unsupported panel type: ' . $type);
        }
        $driver = ($this->factories[$type])();
        if (!$driver instanceof PanelDriverInterface || $driver->type() !== $type) {
            throw new RuntimeException('Driver factory returned an incompatible panel driver.');
        }
        foreach ($driver->capabilities() as $capability) {
            if (!in_array($capability, PanelCapability::all(), true)) {
                throw new RuntimeException('Driver declares an unknown capability.');
            }
        }
        return $driver;
    }
}
