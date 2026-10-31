<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Persistence;

use Closure;
use PDO;
use RuntimeException;

/**
 * Controlled local PostgreSQL write gateway.
 *
 * Disabled unless every safety condition passes.
 *
 * The caller must validate:
 * - Telegram administrator
 * - Panel ownership
 * - Signed confirmation
 * - Confirmation expiration
 */
final class PanelStatusWriteGateway
{
    public function __construct(
        private readonly string $appEnv,
        private readonly string $dbHost,
        private readonly string $writeFlag,
        private readonly string $acknowledgement,
        private readonly Closure $connect,
    ) {
    }

    /**
     * All conditions must be satisfied.
     */
    public function enabled(): bool
    {
        return $this->writeFlag === '1'
            && $this->acknowledgement === 'I_ACCEPT_LOCAL_DB_CHANGES'
            && in_array(
                strtolower($this->appEnv),
                [
                    'local',
                    'development',
                    'testing',
                ],
                true
            )
            && in_array(
                $this->dbHost,
                [
                    '127.0.0.1',
                    'localhost',
                    '::1',
                ],
                true
            );
    }

    /**
     * Execute an owner-scoped database change.
     */
    public function change(
        int $ownerId,
        string $panelId,
        bool $expectedEnabled,
        bool $targetEnabled,
        string $operationId
    ): string {

        if (!$this->enabled()) {
            throw new RuntimeException(
                'Panel status writes are disabled.'
            );
        }

        $pdo = ($this->connect)();

        if (!$pdo instanceof PDO) {
            throw new RuntimeException(
                'Invalid PostgreSQL connection.'
            );
        }

        $service = new PanelStatusChangeService(
            $pdo
        );

        return $service->change(
            $ownerId,
            $panelId,
            $expectedEnabled,
            $targetEnabled,
            $operationId
        );
    }
}