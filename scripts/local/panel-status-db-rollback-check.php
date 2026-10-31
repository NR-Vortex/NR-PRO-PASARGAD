<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

if (($argv[1] ?? '') !== '--local-rollback-test') {
    fwrite(STDERR, "[BLOCKED] Explicit local test authorization required.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Database\Connection;
use NRPro\Pasargad\Modules\Vpn\Persistence\PanelStatusChangeService;

$pdo = null;
$transactionStarted = false;

try {

    echo "\n=== STAGE 21C DATABASE INTEGRATION TEST ===\n";

    /*
     * LOAD LOCAL ENVIRONMENT
     */

    $environment = new Environment(
        $root . '/.env'
    );

    $environment->load();

    $host = (string) $environment->get(
        'DB_HOST',
        ''
    );

    $appEnv = strtolower(
        trim((string) $environment->get('APP_ENV', ''))
    );

    /*
     * NEVER TEST A REMOTE DATABASE
     */

    if (!in_array(
        $host,
        ['127.0.0.1', 'localhost', '::1'],
        true
    )) {
        throw new RuntimeException(
            'Safety stop: DB_HOST must be loopback.'
        );
    }

    if (in_array(
        $appEnv,
        ['production', 'prod'],
        true
    )) {
        throw new RuntimeException(
            'Safety stop: production environment is forbidden.'
        );
    }

    echo "[OK] LOCAL ENVIRONMENT VERIFIED\n";

    /*
     * DATABASE CONFIGURATION
     */

    $config = new Config([
        'database' => [
            'host' => $host,
            'port' => $environment->get(
                'DB_PORT',
                '5433'
            ),
            'name' => $environment->get(
                'DB_DATABASE',
                ''
            ),
            'username' => $environment->get(
                'DB_USERNAME',
                ''
            ),
            'password' => $environment->get(
                'DB_PASSWORD',
                ''
            ),
            'sslmode' => $environment->get(
                'DB_SSLMODE',
                'disable'
            ),
            'connect_timeout' => $environment->get(
                'DB_CONNECT_TIMEOUT',
                '5'
            ),
        ],
    ]);

    $pdo = (new Connection($config))->pdo();

    echo "[OK] POSTGRESQL CONNECTED\n";

    /*
     * VERIFY REQUIRED TABLES
     */

    foreach (
        ['nr_vpn_panels', 'nr_panel_status_audit']
        as $table
    ) {

        $exists = $pdo->query(
            "SELECT to_regclass('public." . $table . "')"
        )->fetchColumn();

        if (
            $exists === false ||
            $exists === null
        ) {
            throw new RuntimeException(
                'Required table missing: ' . $table
            );
        }
    }

    echo "[OK] DATABASE TABLES VERIFIED\n";

    /*
     * START OUTER TEST TRANSACTION
     */

    $service = new PanelStatusChangeService($pdo);

    $pdo->beginTransaction();

    $transactionStarted = true;

    $newOperation = static fn(): string =>
        bin2hex(random_bytes(16));

    /*
     * TEST 1: UNKNOWN PANEL
     */

    $missingPanel = 'missing-'
        . substr($newOperation(), 0, 16);

    $result = $service->change(
        1,
        $missingPanel,
        true,
        false,
        $newOperation()
    );

    if ($result !== 'not_found') {
        throw new RuntimeException(
            'Unknown panel was not rejected.'
        );
    }

    echo "[OK] UNKNOWN PANEL REJECTED\n";

    /*
     * TEST 2: DUPLICATE OPERATION
     *
     * Insert a temporary audit fixture.
     */

    $fakeOperation = $newOperation();

    $insert = $pdo->prepare(<<<'SQL'
INSERT INTO public.nr_panel_status_audit (
    operation_id,
    owner_id,
    panel_id,
    previous_enabled,
    new_enabled
)
VALUES (
    :operation_id,
    1,
    :panel_id,
    TRUE,
    FALSE
)
SQL);

    $insert->execute([
        'operation_id' => $fakeOperation,
        'panel_id' => $missingPanel,
    ]);

    $result = $service->change(
        1,
        $missingPanel,
        true,
        false,
        $fakeOperation
    );

    if ($result !== 'duplicate') {
        throw new RuntimeException(
            'Duplicate operation was not detected.'
        );
    }

    echo "[OK] DUPLICATE OPERATION BLOCKED\n";

    /*
     * TEST 3: FIND A LOCAL PANEL
     *
     * Read only the fields required by this test.
     */

    $row = $pdo->query(<<<'SQL'
SELECT owner_id, panel_id, is_enabled
FROM public.nr_vpn_panels
WHERE owner_id > 0
  AND panel_id::text ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$'
ORDER BY owner_id, panel_id
LIMIT 1
FOR UPDATE
SQL)->fetch(PDO::FETCH_ASSOC);

    $operationToVerify = null;
    $originalState = null;
    $ownerId = null;
    $panelId = null;

    if ($row === false) {

        echo "[SKIP] NO EXISTING LOCAL PANEL\n";
        echo "[INFO] Status-change test requires a registered local panel.\n";

    } else {

        $ownerId = (int) $row['owner_id'];

        $panelId = (string) $row['panel_id'];

        $originalState = in_array(
            $row['is_enabled'],
            [true, 't', '1', 1],
            true
        );

        $targetState = !$originalState;

        /*
         * TEST 4: STALE REQUEST
         */

        $result = $service->change(
            $ownerId,
            $panelId,
            $targetState,
            $originalState,
            $newOperation()
        );

        if ($result !== 'stale') {
            throw new RuntimeException(
                'Stale request was not rejected.'
            );
        }

        echo "[OK] STALE REQUEST BLOCKED\n";

        /*
         * TEST 5: CHANGE STATUS
         *
         * This is NOT committed.
         */

        $operationToVerify = $newOperation();

        $result = $service->change(
            $ownerId,
            $panelId,
            $originalState,
            $targetState,
            $operationToVerify
        );

        if ($result !== 'changed') {
            throw new RuntimeException(
                'Transactional status change failed.'
            );
        }

        /*
         * VERIFY STATUS INSIDE TRANSACTION
         */

        $check = $pdo->prepare(<<<'SQL'
SELECT is_enabled
FROM public.nr_vpn_panels
WHERE owner_id = :owner
  AND panel_id = :panel
SQL);

        $check->execute([
            'owner' => $ownerId,
            'panel' => $panelId,
        ]);

        $after = $check->fetchColumn();

        $afterEnabled = in_array(
            $after,
            [true, 't', '1', 1],
            true
        );

        if ($afterEnabled !== $targetState) {
            throw new RuntimeException(
                'Status was not updated in transaction.'
            );
        }

        echo "[OK] STATUS CHANGED INSIDE TRANSACTION\n";

        /*
         * TEST 6: VERIFY AUDIT LOG
         */

        $audit = $pdo->prepare(<<<'SQL'
SELECT previous_enabled, new_enabled
FROM public.nr_panel_status_audit
WHERE operation_id = :operation
SQL);

        $audit->execute([
            'operation' => $operationToVerify,
        ]);

        $auditRow = $audit->fetch(PDO::FETCH_ASSOC);

        if ($auditRow === false) {
            throw new RuntimeException(
                'Audit log missing.'
            );
        }

        $previous = in_array(
            $auditRow['previous_enabled'],
            [true, 't', '1', 1],
            true
        );

        $new = in_array(
            $auditRow['new_enabled'],
            [true, 't', '1', 1],
            true
        );

        if (
            $previous !== $originalState ||
            $new !== $targetState
        ) {
            throw new RuntimeException(
                'Audit log contains incorrect states.'
            );
        }

        echo "[OK] AUDIT LOG VERIFIED\n";

        /*
         * TEST 7: REPLAY SAME OPERATION
         */

        $result = $service->change(
            $ownerId,
            $panelId,
            $originalState,
            $targetState,
            $operationToVerify
        );

        if ($result !== 'duplicate') {
            throw new RuntimeException(
                'Replay operation was accepted.'
            );
        }

        echo "[OK] REPLAY OPERATION BLOCKED\n";
    }

    /*
     * ROLLBACK ALL TEST CHANGES
     */

    $pdo->rollBack();

    $transactionStarted = false;

    /*
     * VERIFY TEMPORARY AUDIT WAS REMOVED
     */

    $countQuery = $pdo->prepare(<<<'SQL'
SELECT COUNT(*)
FROM public.nr_panel_status_audit
WHERE operation_id = :operation
SQL);

    $countQuery->execute([
        'operation' => $fakeOperation,
    ]);

    if ((int) $countQuery->fetchColumn() !== 0) {
        throw new RuntimeException(
            'Temporary audit row survived rollback.'
        );
    }

    if ($operationToVerify !== null) {

        $countQuery->execute([
            'operation' => $operationToVerify,
        ]);

        if ((int) $countQuery->fetchColumn() !== 0) {
            throw new RuntimeException(
                'Panel audit row survived rollback.'
            );
        }

        /*
         * VERIFY ORIGINAL PANEL STATE RESTORED
         */

        $check = $pdo->prepare(<<<'SQL'
SELECT is_enabled
FROM public.nr_vpn_panels
WHERE owner_id = :owner
  AND panel_id = :panel
SQL);

        $check->execute([
            'owner' => $ownerId,
            'panel' => $panelId,
        ]);

        $restored = $check->fetchColumn();

        $restoredEnabled = in_array(
            $restored,
            [true, 't', '1', 1],
            true
        );

        if ($restoredEnabled !== $originalState) {
            throw new RuntimeException(
                'Original panel state was not restored.'
            );
        }

        echo "[OK] PANEL STATE RESTORED\n";
    }

    echo "[OK] DATABASE ROLLBACK VERIFIED\n";

    echo "[SAFE] NO PERSISTENT PANEL CHANGES\n";

    echo "\n";

    if ($operationToVerify === null) {

        echo "[PARTIAL] DATABASE TEST PASSED WITHOUT A PANEL FIXTURE\n";

    } else {

        echo "[SUCCESS] STAGE 21C PART 6 DATABASE TEST PASSED\n";
    }

} catch (Throwable $error) {

    /*
     * ALWAYS ROLLBACK ON FAILURE
     */

    if (
        $pdo instanceof PDO &&
        $transactionStarted &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    if ($error instanceof PDOException) {

        fwrite(
            STDERR,
            '[FAILED] PostgreSQL error (SQLSTATE '
            . $error->getCode()
            . ')' . PHP_EOL
        );

    } else {

        fwrite(
            STDERR,
            '[FAILED] '
            . $error->getMessage()
            . PHP_EOL
        );
    }

    exit(1);
}