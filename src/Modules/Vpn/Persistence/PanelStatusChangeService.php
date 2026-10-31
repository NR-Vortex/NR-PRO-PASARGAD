<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Persistence;

use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Owner-scoped panel status changes.
 *
 * IMPORTANT:
 * Caller must validate Telegram admin identity
 * and a fresh confirmation before invoking change().
 *
 * This class does not call remote VPN APIs.
 */
final class PanelStatusChangeService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * Results:
     * changed
     * duplicate
     * stale
     * not_found
     */
    public function change(
        int $ownerId,
        string $panelId,
        bool $expectedEnabled,
        bool $targetEnabled,
        string $operationId
    ): string {

        // Validate request before touching the database.

        if (
            $ownerId <= 0 ||
            preg_match(
                '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D',
                $panelId
            ) !== 1 ||
            preg_match(
                '/^[a-f0-9]{32}$/D',
                $operationId
            ) !== 1 ||
            $expectedEnabled === $targetEnabled
        ) {
            throw new InvalidArgumentException(
                'Invalid panel status change request.'
            );
        }

        // Support both standalone and enclosing transactions.

        $ownsTransaction = !$this->pdo->inTransaction();

        $savepoint = 'nr_toggle_'
            . bin2hex(random_bytes(6));

        if ($ownsTransaction) {

            $this->pdo->beginTransaction();

        } else {

            $this->pdo->exec(
                'SAVEPOINT ' . $savepoint
            );
        }

        try {

            // STEP 1: Reject an already executed operation.

            $existing = $this->pdo->prepare(<<<'SQL'
SELECT operation_id
FROM public.nr_panel_status_audit
WHERE operation_id = :operation_id
SQL);

            $existing->execute([
                'operation_id' => $operationId,
            ]);

            if ($existing->fetchColumn() !== false) {

                $this->finish(
                    $ownsTransaction,
                    $savepoint
                );

                return 'duplicate';
            }

            // STEP 2: Lock the requested owner's panel.

            $panel = $this->pdo->prepare(<<<'SQL'
SELECT is_enabled
FROM public.nr_vpn_panels
WHERE owner_id = :owner_id
  AND panel_id = :panel_id
FOR UPDATE
SQL);

            $panel->execute([
                'owner_id' => $ownerId,
                'panel_id' => $panelId,
            ]);

            $row = $panel->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {

                $this->finish(
                    $ownsTransaction,
                    $savepoint
                );

                return 'not_found';
            }

            // STEP 3: Verify expected old status.

            $state = $row['is_enabled'] ?? null;

            if (!in_array(
                $state,
                [true, false, 't', 'f', '1', '0', 1, 0],
                true
            )) {
                throw new RuntimeException(
                    'Unsupported panel status representation.'
                );
            }

            $current = in_array(
                $state,
                [true, 't', '1', 1],
                true
            );

            if ($current !== $expectedEnabled) {

                $this->finish(
                    $ownsTransaction,
                    $savepoint
                );

                return 'stale';
            }

            // STEP 4: Update only this owner's panel.

            $update = $this->pdo->prepare(<<<'SQL'
UPDATE public.nr_vpn_panels
SET is_enabled = :enabled
WHERE owner_id = :owner_id
  AND panel_id = :panel_id
SQL);

            $update->bindValue(
                ':enabled',
                $targetEnabled,
                PDO::PARAM_BOOL
            );

            $update->bindValue(
                ':owner_id',
                $ownerId,
                PDO::PARAM_INT
            );

            $update->bindValue(
                ':panel_id',
                $panelId,
                PDO::PARAM_STR
            );

            $update->execute();

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Panel update did not affect exactly one row.'
                );
            }

            // STEP 5: Create audit record in same transaction.

            $audit = $this->pdo->prepare(<<<'SQL'
INSERT INTO public.nr_panel_status_audit (
    operation_id,
    owner_id,
    panel_id,
    previous_enabled,
    new_enabled
)
VALUES (
    :operation_id,
    :owner_id,
    :panel_id,
    :previous_enabled,
    :new_enabled
)
SQL);

            $audit->bindValue(
                ':operation_id',
                $operationId,
                PDO::PARAM_STR
            );

            $audit->bindValue(
                ':owner_id',
                $ownerId,
                PDO::PARAM_INT
            );

            $audit->bindValue(
                ':panel_id',
                $panelId,
                PDO::PARAM_STR
            );

            $audit->bindValue(
                ':previous_enabled',
                $expectedEnabled,
                PDO::PARAM_BOOL
            );

            $audit->bindValue(
                ':new_enabled',
                $targetEnabled,
                PDO::PARAM_BOOL
            );

            $audit->execute();

            // STEP 6: Commit the complete operation.

            $this->finish(
                $ownsTransaction,
                $savepoint
            );

            return 'changed';

        } catch (Throwable $error) {

            // Restore database state on any failure.

            if ($ownsTransaction) {

                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }

            } else {

                $this->pdo->exec(
                    'ROLLBACK TO SAVEPOINT ' . $savepoint
                );

                $this->pdo->exec(
                    'RELEASE SAVEPOINT ' . $savepoint
                );
            }

            // PostgreSQL unique operation ID violation.

            if (
                $error instanceof PDOException &&
                $error->getCode() === '23505'
            ) {
                return 'duplicate';
            }

            throw $error;
        }
    }

    private function finish(
        bool $ownsTransaction,
        string $savepoint
    ): void {

        if ($ownsTransaction) {

            $this->pdo->commit();

        } else {

            $this->pdo->exec(
                'RELEASE SAVEPOINT ' . $savepoint
            );
        }
    }
}