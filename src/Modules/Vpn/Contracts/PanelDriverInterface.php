<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Contracts;

use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelResult;

/** Drivers never mutate the shared application state directly. */
interface PanelDriverInterface
{
    public function type(): string;

    /** @return list<string> Supported PanelCapability constants. */
    public function capabilities(): array;

    public function ping(PanelConnection $connection): PanelResult;

    public function getAccount(PanelConnection $connection, string $accountId): PanelResult;

    /** @param array<string, mixed> $specification */
    public function createAccount(PanelConnection $connection, array $specification): PanelResult;

    /** @param array<string, mixed> $changes */
    public function updateAccount(PanelConnection $connection, string $accountId, array $changes): PanelResult;

    public function deleteAccount(PanelConnection $connection, string $accountId): PanelResult;
}
