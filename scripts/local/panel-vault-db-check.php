<?php

declare(strict_types=1);

/** Real local PostgreSQL roundtrip in a transaction that ALWAYS rolls back. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Database\Connection;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\Persistence\PanelRepository;
use NRPro\Pasargad\Modules\Vpn\Security\PanelCredentialCipher;

$db = null;
try {
    $env = new Environment($root . '/.env'); $env->load();
    $key = $env->get('NR_PANEL_ENCRYPTION_KEY');
    $cipher = new PanelCredentialCipher($key);
    $config = new Config(['database' => [
        'host' => $env->get('DB_HOST', '127.0.0.1'),
        'port' => $env->get('DB_PORT', '5432'),
        'name' => $env->get('DB_DATABASE', 'nr_pro_pasargad'),
        'username' => $env->get('DB_USERNAME'),
        'password' => $env->get('DB_PASSWORD'),
        'sslmode' => $env->get('DB_SSLMODE', 'disable'),
        'connect_timeout' => $env->get('DB_CONNECT_TIMEOUT', '5'),
    ]]);
    $db = (new Connection($config))->pdo();
    $repo = new PanelRepository($db, $cipher);
    $owner = random_int(900000000000, 999999999999);
    $otherOwner = $owner + 1;
    $id = 'check-' . bin2hex(random_bytes(8));
    $db->beginTransaction();
    $repo->create($owner, 'Stage20 temporary', new PanelConnection($id, 'marzban', 'https://vpn.example.test', ['token' => 'PRIVATE_OFFLINE_TEST']));
    if (count($repo->listMetadata($owner)) !== 1) { throw new RuntimeException('List failed'); }
    $metadata = json_encode($repo->listMetadata($owner), JSON_THROW_ON_ERROR);
    if (str_contains($metadata, 'PRIVATE_OFFLINE_TEST') || str_contains($metadata, 'encrypted_credentials')) {
        throw new RuntimeException('Secret leaked to metadata listing');
    }
    if ($repo->getEnabledConnection($otherOwner, $id) !== null) { throw new RuntimeException('Tenant isolation failed'); }
    $saved = $repo->getEnabledConnection($owner, $id);
    if (!$saved || $saved->credential('token') !== 'PRIVATE_OFFLINE_TEST') { throw new RuntimeException('Decrypted read failed'); }
    if (!$repo->setEnabled($owner, $id, false) || $repo->getEnabledConnection($owner, $id) !== null) {
        throw new RuntimeException('Disable failed');
    }
    if (!$repo->setEnabled($owner, $id, true) || $repo->getEnabledConnection($owner, $id) === null) {
        throw new RuntimeException('Re-enable failed');
    }
    if ($repo->delete($otherOwner, $id) || !$repo->delete($owner, $id)) {
        throw new RuntimeException('Owner-scoped deletion failed');
    }
    echo '[SUCCESS] STAGE20 POSTGRESQL STORE TESTS PASSED; TRANSACTION ROLLED BACK' . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, '[FAILED] STAGE20 DATABASE TEST. Check migrations, DB connection and encryption key.' . PHP_EOL);
    exit(1);
} finally {
    if ($db instanceof PDO && $db->inTransaction()) { $db->rollBack(); }
}
