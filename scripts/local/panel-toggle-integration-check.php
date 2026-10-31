<?php

declare(strict_types=1);

if (
    PHP_SAPI !== 'cli' ||
    ($argv[1] ?? '') !== '--rollback-only'
) {
    fwrite(STDERR, "[BLOCKED] Use --rollback-only.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Database\Connection;
use NRPro\Pasargad\Integrations\Telegram\Commands\TelegramPanelToggleHandler;
use NRPro\Pasargad\Modules\Vpn\Persistence\PanelStatusWriteGateway;
use NRPro\Pasargad\Modules\Vpn\Security\PanelToggleFlow;

$pdo = null;
$transaction = false;

try {

    echo "\n=== NR PRO BOT PART 9 INTEGRATION ===\n";

    // =========================================
    // 1. LOCAL SAFETY
    // =========================================

    $env = new Environment($root . '/.env');
    $env->load();

    $mode = strtolower(
        trim($env->get('APP_ENV', ''))
    );

    $host = trim(
        $env->get('DB_HOST', '')
    );

    $port = $env->get('DB_PORT', '');

    $flag = $env->get(
        'NR_PANEL_STATUS_WRITE_ENABLED',
        '0'
    );

    if (
        !in_array(
            $mode,
            ['local', 'development', 'testing'],
            true
        ) ||
        !in_array(
            $host,
            ['127.0.0.1', 'localhost', '::1'],
            true
        ) ||
        $port !== '5433' ||
        $flag !== '0'
    ) {
        throw new RuntimeException(
            'Safety check failed. Local PostgreSQL and disabled Worker writes required.'
        );
    }

    echo "[OK] LOCAL SAFETY VERIFIED\n";

    // =========================================
    // 2. CONNECT POSTGRESQL
    // =========================================

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

    foreach (
        ['nr_vpn_panels', 'nr_panel_status_audit']
        as $table
    ) {

        $exists = $pdo->query(
            "SELECT to_regclass('public.$table')"
        )->fetchColumn();

        if (!is_string($exists) || $exists === '') {
            throw new RuntimeException(
                'Missing table: ' . $table
            );
        }
    }

    // Block custom triggers that might have side effects.

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

    if ($triggers !== 0) {
        throw new RuntimeException(
            'Custom enabled triggers detected. Test blocked.'
        );
    }

    echo "[OK] POSTGRESQL CONNECTED\n";

    // =========================================
    // 3. START ROLLBACK-ONLY TRANSACTION
    // =========================================

    $pdo->beginTransaction();

    $transaction = true;

    $pdo->exec("SET LOCAL lock_timeout = '3s'");
    $pdo->exec("SET LOCAL statement_timeout = '10s'");

    $owner = 731947163;

    $panelId = 'stage9-'
        . bin2hex(random_bytes(10));

    $messageId = 4401;

    // Test data only. No real credentials.

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
    :owner,
    :panel,
    'Stage 9 rollback test',
    'test_fixture',
    'https://stage9.invalid',
    'NOT_A_REAL_SECRET',
    TRUE
)
SQL);

    $insert->execute([
        'owner' => $owner,
        'panel' => $panelId,
    ]);

    echo "[OK] TEMPORARY PANEL CREATED\n";

    // =========================================
    // 4. INITIALIZE TELEGRAM ADMIN HANDLER
    // =========================================

    $adminConfig = new Config([
        'telegram' => [
            'admin_id' => (string) $owner,
        ],
    ]);

    // Fake cryptographic key for this test only.

    $flow = new PanelToggleFlow(
        str_repeat('STAGE9-FAKE-KEY-', 3)
    );

    $handler = new TelegramPanelToggleHandler(
        $adminConfig,
        $flow
    );

    // Owner-scoped database lookup.

    $listPanels = static function (
        int $requestedOwner
    ) use ($pdo, $panelId): array {

        $query = $pdo->prepare(<<<'SQL'
SELECT panel_id, is_enabled
FROM public.nr_vpn_panels
WHERE owner_id = :owner
AND panel_id = :panel
SQL);

        $query->execute([
            'owner' => $requestedOwner,
            'panel' => $panelId,
        ]);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    };

    // Test-only gateway.
    // The actual Worker write flag stays disabled.

    $gateway = new PanelStatusWriteGateway(
        'local',
        '127.0.0.1',
        '1',
        'I_ACCEPT_LOCAL_DB_CHANGES',
        static fn (): PDO => $pdo
    );

    $writes = 0;

    $changeStatus = static function (
        int $ownerId,
        string $id,
        bool $expected,
        bool $target,
        string $operation
    ) use ($gateway, &$writes): string {

        ++$writes;

        return $gateway->change(
            $ownerId,
            $id,
            $expected,
            $target,
            $operation
        );
    };

    echo "[OK] HANDLER AND GATEWAY INITIALIZED\n";

    // =========================================
    // 5. SIMULATE REQUEST CALLBACK
    // =========================================

    $keyboard = $flow->panelActions(
        $panelId,
        true
    );

    $requestData = $keyboard[
        'inline_keyboard'
    ][0][0]['callback_data'];

    $job = [
        'update_id' => 9001,
        'user_id' => $owner,
        'chat_id' => $owner,
        'command' => 'admin_toggle',
        'callback_id' => 'offline-stage9',
        'message_id' => $messageId,
        'callback_data' => $requestData,
    ];

    $reply = $handler->render(
        $job,
        $listPanels,
        $changeStatus,
        false
    );

    $token = $reply[
        'reply_markup'
    ]['inline_keyboard'][0][0]['callback_data'] ?? '';

    if (
        !is_string($token) ||
        !str_starts_with($token, 'nr:t:c:') ||
        $writes !== 0
    ) {
        throw new RuntimeException(
            'Confirmation generation failed.'
        );
    }

    echo "[OK] CONFIRMATION GENERATED\n";

    // =========================================
    // 6. REJECT UNAUTHORIZED ADMIN
    // =========================================

    $job['callback_data'] = $token;

    $badJob = $job;

    $badJob['user_id'] = $owner + 1;
    $badJob['chat_id'] = $owner + 1;

    $blocked = false;

    try {

        $handler->render(
            $badJob,
            $listPanels,
            $changeStatus,
            true
        );

    } catch (InvalidArgumentException) {

        $blocked = true;
    }

    if (!$blocked || $writes !== 0) {
        throw new RuntimeException(
            'Unauthorized admin accepted.'
        );
    }

    echo "[OK] UNAUTHORIZED ADMIN BLOCKED\n";

    // =========================================
    // 7. REJECT WRONG MESSAGE
    // =========================================

    $badJob = $job;

    $badJob['message_id'] = $messageId + 1;

    $result = $handler->render(
        $badJob,
        $listPanels,
        $changeStatus,
        true
    );

    if (
        !str_contains(
            $result['text'],
            'نامعتبر'
        ) ||
        $writes !== 0
    ) {
        throw new RuntimeException(
            'Cross-message confirmation accepted.'
        );
    }

    echo "[OK] WRONG MESSAGE BLOCKED\n";

    // =========================================
    // 8. REJECT EXPIRED CONFIRMATION
    // =========================================

    $expiredKeyboard = $flow->confirmationKeyboard(
        $owner,
        $messageId,
        $panelId,
        false,
        time() - 181
    );

    $expiredJob = $job;

    $expiredJob['callback_data'] =
        $expiredKeyboard[
            'inline_keyboard'
        ][0][0]['callback_data'];

    $result = $handler->render(
        $expiredJob,
        $listPanels,
        $changeStatus,
        true
    );

    if (
        !str_contains(
            $result['text'],
            'منقضی'
        ) ||
        $writes !== 0
    ) {
        throw new RuntimeException(
            'Expired confirmation accepted.'
        );
    }

    echo "[OK] EXPIRED CONFIRMATION BLOCKED\n";

    // =========================================
    // 9. VERIFY DEFAULT WRITE PROTECTION
    // =========================================

    $result = $handler->render(
        $job,
        $listPanels,
        $changeStatus,
        false
    );

    if (
        !str_contains(
            $result['text'],
            'غیرفعال'
        ) ||
        $writes !== 0
    ) {
        throw new RuntimeException(
            'Default write gate bypassed.'
        );
    }

    echo "[OK] DATABASE WRITE GATE VERIFIED\n";

    // =========================================
    // 10. TEST ISOLATED DATABASE WRITE
    // =========================================

    // IMPORTANT:
    // The write happens only to our temporary
    // fixture inside the outer transaction.

    $result = $handler->render(
        $job,
        $listPanels,
        $changeStatus,
        true
    );

    if (
        !str_contains(
            $result['text'],
            'در دیتابیس تغییر کرد'
        ) ||
        $writes !== 1
    ) {
        throw new RuntimeException(
            'Authorized panel update failed.'
        );
    }

    echo "[OK] AUTHORIZED TEST UPDATE EXECUTED\n";

    // =========================================
    // 11. VERIFY DATABASE STATE
    // =========================================

    $read = $pdo->prepare(<<<'SQL'
SELECT is_enabled
FROM public.nr_vpn_panels
WHERE owner_id = :owner
AND panel_id = :panel
SQL);

    $read->execute([
        'owner' => $owner,
        'panel' => $panelId,
    ]);

    $enabled = in_array(
        $read->fetchColumn(),
        [true, 't', '1', 1],
        true
    );

    if ($enabled) {
        throw new RuntimeException(
            'Temporary panel was not disabled.'
        );
    }

    echo "[OK] TEMPORARY PANEL STATUS VERIFIED\n";

    // =========================================
    // 12. VERIFY AUDIT RECORD
    // =========================================

    $verified = $flow->verifyConfirmation(
        $token,
        $owner,
        $messageId,
        $panelId
    );

    if ($verified === null) {
        throw new RuntimeException(
            'Signed confirmation verification failed.'
        );
    }

    $operationId = $verified['operation_id'];

    $audit = $pdo->prepare(<<<'SQL'
SELECT previous_enabled, new_enabled
FROM public.nr_panel_status_audit
WHERE operation_id = :operation
AND owner_id = :owner
AND panel_id = :panel
SQL);

    $audit->execute([
        'operation' => $operationId,
        'owner' => $owner,
        'panel' => $panelId,
    ]);

    $auditRow = $audit->fetch(PDO::FETCH_ASSOC);

    if (
        !is_array($auditRow) ||
        !in_array(
            $auditRow['previous_enabled'],
            [true, 't', '1', 1],
            true
        ) ||
        in_array(
            $auditRow['new_enabled'],
            [true, 't', '1', 1],
            true
        )
    ) {
        throw new RuntimeException(
            'Audit record verification failed.'
        );
    }

    echo "[OK] AUDIT LOG VERIFIED\n";

    // =========================================
    // 13. PREVENT REPLAY
    // =========================================

    $result = $handler->render(
        $job,
        $listPanels,
        $changeStatus,
        true
    );

    if (
        $writes !== 1 ||
        !str_contains(
            $result['text'],
            'تغییر کرده'
        )
    ) {
        throw new RuntimeException(
            'Repeated callback was not blocked.'
        );
    }

    $duplicate = $gateway->change(
        $owner,
        $panelId,
        true,
        false,
        $operationId
    );

    if ($duplicate !== 'duplicate') {
        throw new RuntimeException(
            'Database replay protection failed.'
        );
    }

    echo "[OK] DUPLICATE OPERATION BLOCKED\n";

    // =========================================
    // 14. OWNER-SCOPED DATABASE CHECK
    // =========================================

    $wrongOwnerResult = $gateway->change(
        $owner + 1,
        $panelId,
        false,
        true,
        bin2hex(random_bytes(16))
    );

    if ($wrongOwnerResult !== 'not_found') {
        throw new RuntimeException(
            'Wrong owner database request accepted.'
        );
    }

    echo "[OK] PANEL OWNERSHIP VERIFIED\n";

    // =========================================
    // 15. ROLLBACK ALL TEST CHANGES
    // =========================================

    $pdo->rollBack();

    $transaction = false;

    $read->execute([
        'owner' => $owner,
        'panel' => $panelId,
    ]);

    if ($read->fetchColumn() !== false) {
        throw new RuntimeException(
            'Temporary panel survived rollback.'
        );
    }

    $audit->execute([
        'operation' => $operationId,
        'owner' => $owner,
        'panel' => $panelId,
    ]);

    if ($audit->fetch(PDO::FETCH_ASSOC) !== false) {
        throw new RuntimeException(
            'Temporary audit record survived rollback.'
        );
    }

    echo "[OK] DATABASE ROLLBACK VERIFIED\n";

    echo "\n";
    echo "[SUCCESS] STAGE 21C PART 9 INTEGRATION PASSED\n";
    echo "[SAFE] NO PERSISTENT DATABASE CHANGES\n";
    echo "[SAFE] NO REDIS OR TELEGRAM REQUESTS\n";
    echo "[SAFE] NO VPS CHANGES\n";

} catch (Throwable $error) {

    if (
        $transaction &&
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    $reason = $error instanceof PDOException
        ? 'PostgreSQL SQLSTATE ' . $error->getCode()
        : $error->getMessage();

    fwrite(
        STDERR,
        '[FAILED] ' . $reason . PHP_EOL
    );

    exit(1);
}