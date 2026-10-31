<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn;

use InvalidArgumentException;
use NRPro\Pasargad\Modules\Vpn\Contracts\PanelDriverInterface;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelResult;

/** Panel operations are explicit and capability-gated. No implicit fallback writes. */
final class PanelManager
{
    public function __construct(private readonly PanelRegistry $registry)
    {
    }

    public function ping(PanelConnection $connection): PanelResult
    {
        return $this->run($connection, PanelCapability::PING,
            static fn (PanelDriverInterface $driver): PanelResult => $driver->ping($connection));
    }

    public function getAccount(PanelConnection $connection, string $accountId): PanelResult
    {
        self::requireAccountId($accountId);
        return $this->run($connection, PanelCapability::READ_ACCOUNT,
            static fn (PanelDriverInterface $driver): PanelResult => $driver->getAccount($connection, $accountId));
    }

    /** @param array<string, mixed> $specification */
    public function createAccount(PanelConnection $connection, array $specification): PanelResult
    {
        return $this->run($connection, PanelCapability::CREATE_ACCOUNT,
            static fn (PanelDriverInterface $driver): PanelResult => $driver->createAccount($connection, $specification));
    }

    /** @param array<string, mixed> $changes */
    public function updateAccount(PanelConnection $connection, string $accountId, array $changes): PanelResult
    {
        self::requireAccountId($accountId);
        return $this->run($connection, PanelCapability::UPDATE_ACCOUNT,
            static fn (PanelDriverInterface $driver): PanelResult => $driver->updateAccount($connection, $accountId, $changes));
    }

    public function deleteAccount(PanelConnection $connection, string $accountId): PanelResult
    {
        self::requireAccountId($accountId);
        return $this->run($connection, PanelCapability::DELETE_ACCOUNT,
            static fn (PanelDriverInterface $driver): PanelResult => $driver->deleteAccount($connection, $accountId));
    }

    /** @param \Closure(PanelDriverInterface):PanelResult $operation */
    private function run(PanelConnection $connection, string $capability, \Closure $operation): PanelResult
    {
        $driver = $this->registry->driver($connection->type);
        if (!in_array($capability, $driver->capabilities(), true)) {
            return PanelResult::unsupported($capability);
        }
        return $operation($driver);
    }

    private static function requireAccountId(string $accountId): void
    {
        if (trim($accountId) === '' || strlen($accountId) > 512) {
            throw new InvalidArgumentException('Invalid account identifier.');
        }
    }
}
