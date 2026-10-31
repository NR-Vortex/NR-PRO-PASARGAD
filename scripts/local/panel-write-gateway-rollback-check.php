<?php

declare(strict_types=1);

if (
    PHP_SAPI !== 'cli' ||
    ($argv[1] ?? '') !== '--rollback-only'
) {
    fwrite(STDERR, "[BLOCKED] Use --rollback-only.\n");
    exit(2);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Database\Connection;
use NRPro\Pasargad\Modules\Vpn\Persistence\PanelStatusWriteGateway;

$pdo = null;
$inTestTransaction = false;

try {

    $root = dirname(__DIR__, 2);

    $env = new Environment($root . '/.env');
    $env->load();

    $host = trim($env->get('DB_HOST', ''));
    $port = $env->get('DB_PORT', '');

    $mode = strtolower(
        trim($env->get('APP_ENV', ''))
    );

    $flag = $env->get(
        'NR_PANEL_STATUS_WRITE_ENABLED',
        '0'
    );

    // SAFETY: LOCAL DATABASE ONLY

    if (
        !in_array(
            $host,
            ['127.0.0.1', 'localhost', '::1'],
            true
        ) ||
        $port !== '5433' ||
        !in_array(
            $mode,
            ['local', 'development', 'testing'],
            true
        ) ||
        $flag !== '0'
    ) {
        throw new RuntimeException(
            'Safety check failed. Require local APP_ENV, '
            . 'loopback DB_HOST, DB_PORT=5433 and '
            . 'NR_PANEL_STATUS_WRITE_ENABLED=0.'
        );
    }

    echo "[OK] LOCAL SAFETY VERIFIED\n";

    // DATABASE CONNECTION

    $config = new Config([
        'database' => [
            'host' => $host,
            'port' => $port,
            'name' => $env->get('DB_DATABASE', ''),
            'username' => $env->get('DB_USERNAME', ''),
            'password' => $env->get('DB_PASSWORD', ''),
            'sslmode' => $env->get('DB_SSLMODE', 'disable'),
            'connect_timeout' => '5',
        ],
    ]);

    $pdo = (new Connection($config))->pdo();

    echo "[OK] POSTGRESQL CONNECTED\n";

    // VERIFY TABLES

    foreach (
        ['nr_vpn_panels', 'nr_panel_status_audit']
        as $table
    ) {
        $found = $pdo->query(
            "SELECT to_regclass('public.$table')"
        )->fetchColumn();

        if (!is_string($found) || $found === '') {
            throw new RuntimeException(
                "Missing database table: $table"
            );
        }
    }

    // Avoid running tests when custom triggers
    // might cause external side effects.

    $triggers = (int) $pdo->query(<<<'SQL'
SELECT COUNT(*)
FROM pg_trigger
WHERE tgrelid IN (
    'public.nr_vpn_panels'::regclass,
    'public.nr_panel_status_audit'::regclass
)
AND NOT tgisinternal
AND tgenabled <> 'D'
SQL)->fetchColumn();

    if ($triggers > 0) {
        throw new RuntimeException(
            'Custom enabled triggers found. Manual review required.'
        );
    }

    echo "[OK] TABLES VERIFIED\n";

    // START OUTER TRANSACTION

    $pdo->beginTransaction();
    $inTestTransaction = true;

    $pdo->exec("SET LOCAL lock_timeout = '3s'");
    $pdo->exec("SET LOCAL statement_timeout = '10s'");

    // NR_STAGE8_TEMP_FIXTURE
    // The outer transaction is already active.
    // No test record is committed.

    $fixtureOwner = 8000000000000000000;

    $fixturePanel = 'nr-test-'
        . bin2hex(random_bytes(8));

    $insert = $pdo->prepare(<<<'SQL'
INSERT INTO public.nr_vpn_panels (
    owner_id,
    panel_id,
    display_name,
    driver_type,
    base_url,
    encrypted_credentials,
    is_enabled
)
VALUES (
    :owner_id,
    :panel_id,
    :display_name,
    :driver_type,
    :base_url,
    :encrypted_credentials,
    TRUE
)
SQL);

    $insert->execute([
        'owner_id' => $fixtureOwner,
        'panel_id' => $fixturePanel,
        'display_name' => 'NR ROLLBACK TEST',
        'driver_type' => 'test_fixture',
        'base_url' => 'https://example.invalid',
        'encrypted_credentials' => 'TEST_ONLY_NOT_A_REAL_SECRET',
    ]);

    echo "[OK] TEMPORARY TEST PANEL INSERTED\n";

    // Select only our synthetic panel.
    // Never modify an existing real panel.

    $select = $pdo->prepare(<<<'SQL'
SELECT owner_id, panel_id, is_enabled
FROM public.nr_vpn_panels
WHERE owner_id = :owner_id
AND panel_id = :panel_id
FOR UPDATE
SQL);

    $select->execute([
        'owner_id' => $fixtureOwner,
        'panel_id' => $fixturePanel,
    ]);

    $row = $select->fetch(PDO::FETCH_ASSOC);

    if ($row === false) {
        throw new RuntimeException(
            'Temporary test panel was not found.'
        );
    }

    $owner = (int) $row['owner_id'];
    $panelId = (string) $row['panel_id'];

    $before = in_array(
        $row['is_enabled'],
        [true, 't', '1', 1],
        true
    );

    $after = !$before;

    // GENERATE A DIFFERENT OWNER

    $wrongOwner = $owner === 9000000000000000000
        ? 8999999999999999999
        : 9000000000000000000;

    $exists = $pdo->prepare(<<<'SQL'
SELECT 1
FROM public.nr_vpn_panels
WHERE owner_id = :owner
AND panel_id = :panel
SQL);

    $exists->execute([
        'owner' => $wrongOwner,
        'panel' => $panelId,
    ]);

    if ($exists->fetchColumn() !== false) {
        throw new RuntimeException(
            'Synthetic owner conflicts with an existing record.'
        );
    }

    // TEST-ONLY GATEWAY
    // The real Worker write gate remains disabled.

    $gateway = new PanelStatusWriteGateway(
        'local',
        '127.0.0.1',
        '1',
        'I_ACCEPT_LOCAL_DB_CHANGES',
        static fn (): PDO => $pdo
    );

    $newId = static fn (): string =>
        bin2hex(random_bytes(16));

    // TEST 1: OWNER PROTECTION

    $result = $gateway->change(
        $wrongOwner,
        $panelId,
        $before,
        $after,
        $newId()
    );

    if ($result !== 'not_found') {
        throw new RuntimeException(
            'Wrong owner accepted.'
        );
    }

    echo "[OK] WRONG OWNER BLOCKED\n";

    // TEST 2: STALE REQUEST

    $result = $gateway->change(
        $owner,
        $panelId,
        $after,
        $before,
        $newId()
    );

    if ($result !== 'stale') {
        throw new RuntimeException(
            'Stale request accepted.'
        );
    }

    echo "[OK] STALE REQUEST BLOCKED\n";

    // TEST 3: TRANSACTIONAL UPDATE

    $operationId = $newId();

    $result = $gateway->change(
        $owner,
        $panelId,
        $before,
        $after,
        $operationId
    );

    if ($result !== 'changed') {
        throw new RuntimeException(
            'Transactional update failed.'
        );
    }

    echo "[OK] STATUS CHANGED INSIDE TRANSACTION\n";

    // TEST 4: DUPLICATE PROTECTION

    $result = $gateway->change(
        $owner,
        $panelId,
        $before,
        $after,
        $operationId
    );

    if ($result !== 'duplicate') {
        throw new RuntimeException(
            'Duplicate request accepted.'
        );
    }

    echo "[OK] DUPLICATE BLOCKED\n";

    // TEST 5: VERIFY STATUS

    $state = $pdo->prepare(<<<'SQL'
SELECT is_enabled
FROM public.nr_vpn_panels
WHERE owner_id = :owner
AND panel_id = :panel
SQL);

    $state->execute([
        'owner' => $owner,
        'panel' => $panelId,
    ]);

    $current = in_array(
        $state->fetchColumn(),
        [true, 't', '1', 1],
        true
    );

    if ($current !== $after) {
        throw new RuntimeException(
            'Panel status verification failed.'
        );
    }

    echo "[OK] NEW STATUS VERIFIED\n";

    // TEST 6: VERIFY AUDIT LOG

    $audit = $pdo->prepare(<<<'SQL'
SELECT
    owner_id,
    panel_id,
    previous_enabled,
    new_enabled
FROM public.nr_panel_status_audit
WHERE operation_id = :id
SQL);

    $audit->execute([
        'id' => $operationId,
    ]);

    $log = $audit->fetch(PDO::FETCH_ASSOC);

    if (
        !is_array($log) ||
        (int) $log['owner_id'] !== $owner ||
        $log['panel_id'] !== $panelId ||
        in_array(
            $log['previous_enabled'],
            [true, 't', '1', 1],
            true
        ) !== $before ||
        in_array(
            $log['new_enabled'],
            [true, 't', '1', 1],
            true
        ) !== $after
    ) {
        throw new RuntimeException(
            'Audit verification failed.'
        );
    }

    echo "[OK] AUDIT LOG VERIFIED\n";

    // ROLLBACK ALL TEST CHANGES

    $pdo->rollBack();
    $inTestTransaction = false;

    // VERIFY TEMPORARY PANEL WAS REMOVED
    // AFTER THE OUTER TRANSACTION ROLLBACK

    $state->execute([
        'owner' => $owner,
        'panel' => $panelId,
    ]);

    if ($state->fetchColumn() !== false) {
        throw new RuntimeException(
            'Temporary panel survived rollback.'
        );
    }
    // VERIFY AUDIT WAS ROLLED BACK

    $audit->execute([
        'id' => $operationId,
    ]);

    if ($audit->fetch(PDO::FETCH_ASSOC) !== false) {
        throw new RuntimeException(
            'Audit row survived rollback.'
        );
    }

    echo "[OK] ROLLBACK VERIFIED\n";
    echo "[OK] TEMPORARY TEST PANEL REMOVED\n";
    echo "[OK] TEMPORARY AUDIT REMOVED\n";

    echo "\n";
    echo "[SUCCESS] STAGE 21C PART 8 PASSED\n";
    echo "[SAFE] NO PERSISTENT CHANGES\n";

} catch (Throwable $error) {

    if (
        $inTestTransaction &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    fwrite(
        STDERR,
        '[FAILED] ' .
        (
            $error instanceof PDOException
                ? 'PostgreSQL SQLSTATE ' . $error->getCode()
                : $error->getMessage()
        ) .
        PHP_EOL
    );

    exit(1);
}