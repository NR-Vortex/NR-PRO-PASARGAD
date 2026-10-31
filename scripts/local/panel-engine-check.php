<?php

declare(strict_types=1);

/** Offline tests: no network, no database, no Telegram calls, no secrets printed. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$project = dirname(__DIR__, 2);
$autoload = $project . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($project): void {
        $prefix = 'NRPro\\Pasargad\\';
        if (str_starts_with($class, $prefix)) {
            $path = $project . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    });
}

use NRPro\Pasargad\Modules\Vpn\Contracts\PanelDriverInterface;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelResult;
use NRPro\Pasargad\Modules\Vpn\PanelCapability;
use NRPro\Pasargad\Modules\Vpn\PanelManager;
use NRPro\Pasargad\Modules\Vpn\PanelRegistry;

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

try {
    $factoryCalls = 0;
    $writes = 0;
    $registry = new PanelRegistry();
    $registry->register('marzban', static function () use (&$factoryCalls, &$writes): PanelDriverInterface {
        ++$factoryCalls;
        return new class($writes) implements PanelDriverInterface {
            public function __construct(private mixed &$writeCounter) {}
            public function type(): string { return 'marzban'; }
            public function capabilities(): array { return [PanelCapability::PING, PanelCapability::READ_ACCOUNT, PanelCapability::CREATE_ACCOUNT]; }
            public function ping(PanelConnection $connection): PanelResult { return PanelResult::ok(['panel' => $connection->id]); }
            public function getAccount(PanelConnection $connection, string $accountId): PanelResult {
                return PanelResult::ok(['panel' => $connection->id, 'account' => $accountId]);
            }
            public function createAccount(PanelConnection $connection, array $specification): PanelResult {
                ++$this->writeCounter;
                return PanelResult::ok(['panel' => $connection->id, 'account' => $specification['username'] ?? '']);
            }
            public function updateAccount(PanelConnection $connection, string $accountId, array $changes): PanelResult { throw new LogicException('Not implemented'); }
            public function deleteAccount(PanelConnection $connection, string $accountId): PanelResult { throw new LogicException('Not implemented'); }
        };
    });
    $registry->register('xui', static function (): PanelDriverInterface {
        return new class implements PanelDriverInterface {
            public function type(): string { return 'xui'; }
            public function capabilities(): array { return [PanelCapability::PING]; }
            public function ping(PanelConnection $connection): PanelResult { return PanelResult::ok(['panel' => $connection->id]); }
            public function getAccount(PanelConnection $connection, string $accountId): PanelResult { throw new LogicException('Not implemented'); }
            public function createAccount(PanelConnection $connection, array $specification): PanelResult { throw new LogicException('Not implemented'); }
            public function updateAccount(PanelConnection $connection, string $accountId, array $changes): PanelResult { throw new LogicException('Not implemented'); }
            public function deleteAccount(PanelConnection $connection, string $accountId): PanelResult { throw new LogicException('Not implemented'); }
        };
    });

    $manager = new PanelManager($registry);
    $a = new PanelConnection('north-1', 'marzban', 'https://vpn-a.example.test', ['token' => 'SUPER_SECRET']);
    $b = new PanelConnection('south-2', 'marzban', 'https://vpn-b.example.test', ['token' => 'OTHER_SECRET']);
    $c = new PanelConnection('wire-3', 'xui', 'https://vpn-c.example.test');
    verify(count($registry->types()) === 2 && $factoryCalls === 0, 'DRIVERS REGISTERED LAZILY');
    verify($manager->ping($a)->data['panel'] === 'north-1', 'PANEL 1 PING');
    verify($manager->ping($b)->data['panel'] === 'south-2', 'PANEL 2 PING / SAME DRIVER TYPE');
    verify($manager->getAccount($b, 'test-user')->data['account'] === 'test-user', 'READ ACCOUNT');
    verify($manager->createAccount($a, ['username' => 'test-user'])->success && $writes === 1, 'CREATE ACCOUNT THROUGH DRIVER (MOCK ONLY)');
    verify($manager->deleteAccount($a, 'test-user')->code === 'unsupported_capability', 'UNSUPPORTED DELETE REJECTED');
    verify($manager->createAccount($c, ['username' => 'test-user'])->code === 'unsupported_capability' && $writes === 1, 'NO CROSS-PANEL FALLBACK WRITE');
    verify($factoryCalls >= 4, 'LAZY DRIVER INSTANCES BUILT');
    ob_start();
    var_dump($a);
    $debugOutput = (string) ob_get_clean();
    verify(!str_contains($debugOutput, 'SUPER_SECRET') && str_contains($debugOutput, '[REDACTED]'), 'CREDENTIALS REDACTED FROM VAR_DUMP');
    $rejected = false;
    try { new PanelConnection('bad', 'xui', 'http://example.test', ['token'=>'secret']); }
    catch (InvalidArgumentException) { $rejected = true; }
    verify($rejected, 'INSECURE HTTP DENIED BY DEFAULT');
    $rejected = false;
    try { $manager->ping(new PanelConnection('unknown', 'unknown', 'https://example.test')); }
    catch (InvalidArgumentException) { $rejected = true; }
    verify($rejected, 'UNKNOWN PANEL DENIED');
    echo '[SUCCESS] UNIVERSAL PANEL ENGINE CONTRACT TESTS PASSED (OFFLINE ONLY)' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[FAILED] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
