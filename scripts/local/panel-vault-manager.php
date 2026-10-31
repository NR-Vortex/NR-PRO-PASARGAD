<?php

declare(strict_types=1);

/** Local administrator CLI; NOT exposed through Telegram or public HTTP. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Database\Connection;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\PanelDrivers;
use NRPro\Pasargad\Modules\Vpn\PanelManager;
use NRPro\Pasargad\Modules\Vpn\Persistence\PanelRepository;
use NRPro\Pasargad\Modules\Vpn\Security\PanelCredentialCipher;

try {
    $command = $argv[1] ?? '';
    if (!in_array($command, ['add', 'list', 'enable', 'disable', 'delete', 'ping'], true)) {
        throw new InvalidArgumentException('Usage: panel-vault-manager.php add|list|enable|disable|delete|ping');
    }
    $env = new Environment($root . '/.env'); $env->load();
    $cipher = new PanelCredentialCipher($env->get('NR_PANEL_ENCRYPTION_KEY'));
    $config = new Config(['database' => [
        'host' => $env->get('DB_HOST', '127.0.0.1'),
        'port' => $env->get('DB_PORT', '5432'),
        'name' => $env->get('DB_DATABASE', 'nr_pro_pasargad'),
        'username' => $env->get('DB_USERNAME'),
        'password' => $env->get('DB_PASSWORD'),
        'sslmode' => $env->get('DB_SSLMODE', 'disable'),
        'connect_timeout' => $env->get('DB_CONNECT_TIMEOUT', '5'),
    ]]);
    $repo = new PanelRepository((new Connection($config))->pdo(), $cipher);
    if ($command === 'add') {
        if (count($argv) !== 2) { throw new InvalidArgumentException('Add accepts a JSON object from stdin only.'); }
        $json = stream_get_contents(STDIN, 20001);
        if (!is_string($json) || strlen($json) > 20000) { throw new InvalidArgumentException('Invalid input size.'); }
        $body = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_diff(array_keys($body), ['owner_id', 'panel_id', 'name', 'type', 'url', 'credentials'])) {
            throw new InvalidArgumentException('Invalid panel input.');
        }
        $owner = filter_var($body['owner_id'] ?? null, FILTER_VALIDATE_INT);
        $type = $body['type'] ?? null;
        if (!is_int($owner) || $owner <= 0 || !is_string($type) || !in_array($type, PanelDrivers::standard()->types(), true)) {
            throw new InvalidArgumentException('Invalid owner or unsupported driver.');
        }
        $connection = new PanelConnection($body['panel_id'] ?? '', $type, $body['url'] ?? '', $body['credentials'] ?? []);
        $repo->create($owner, $body['name'] ?? '', $connection);
        echo '[OK] Panel registered with encrypted secrets; no network request was sent.' . PHP_EOL;
    } else {
        if (!isset($argv[2]) || !ctype_digit($argv[2]) || (int)$argv[2] <= 0) {
            throw new InvalidArgumentException('Expected numeric owner ID.');
        }
        $owner = (int)$argv[2];
        if ($command === 'list') {
            if (count($argv) !== 3) { throw new InvalidArgumentException('list <owner_id>'); }
            echo json_encode($repo->listMetadata($owner), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        } else {
            $id = $argv[3] ?? '';
            if ($command === 'enable' || $command === 'disable') {
                if (count($argv) !== 4) { throw new InvalidArgumentException('enable|disable <owner_id> <panel_id>'); }
                $result = $repo->setEnabled($owner, $id, $command === 'enable');
                echo $result ? '[OK] Panel status updated.' . PHP_EOL : '[INFO] Panel not found.' . PHP_EOL;
            } elseif ($command === 'delete') {
                if (count($argv) !== 5 || $argv[4] !== '--confirm-delete') {
                    throw new InvalidArgumentException('delete <owner_id> <panel_id> --confirm-delete');
                }
                echo $repo->delete($owner, $id) ? '[OK] Panel connection removed; remote VPN users unchanged.' . PHP_EOL : '[INFO] Panel not found.' . PHP_EOL;
            } elseif ($command === 'ping') {
                if (count($argv) !== 4) { throw new InvalidArgumentException('ping <owner_id> <panel_id>'); }
                $connection = $repo->getEnabledConnection($owner, $id);
                if ($connection === null) { throw new RuntimeException('Panel not found or disabled.'); }
                $result = (new PanelManager(PanelDrivers::standard()))->ping($connection);
                echo $result->success ? '[OK] Remote panel authenticated.' . PHP_EOL : '[ERROR] Panel ping failed: ' . $result->code . PHP_EOL;
                exit($result->success ? 0 : 1);
            }
        }
    }
} catch (Throwable) {
    fwrite(STDERR, '[ERROR] Panel operation failed. Check inputs, DB, migration, encryption key and driver compatibility.' . PHP_EOL);
    exit(1);
}
