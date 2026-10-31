<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$project = dirname(__DIR__, 2);
if (is_file($project . '/vendor/autoload.php')) {
    require_once $project . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($project): void {
        $prefix = 'NRPro\\Pasargad\\';
        if (str_starts_with($class, $prefix)) {
            $path = $project . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) { require $path; }
        }
    });
}

use NRPro\Pasargad\Modules\Vpn\Security\PanelCredentialCipher;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;

function check20(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException('FAIL: ' . $label); }
    echo '[OK] ' . $label . PHP_EOL;
}

function rejects20(Closure $fn): bool
{
    try { $fn(); } catch (Throwable) { return true; }
    return false;
}

try {
    $key = PanelCredentialCipher::generateKey();
    $cipher = new PanelCredentialCipher($key);
    $aad = PanelCredentialCipher::context(12345, 'eu-1', 'marzban', 'https://vpn.example.test');
    $secret = 'STAGE20_SECRET_TOKEN_NEVER_PRINT';
    $encrypted = $cipher->encrypt(['token' => $secret, 'username' => 'admin'], $aad);
    check20(str_starts_with($encrypted, 'v1:') && !str_contains($encrypted, $secret), 'CREDENTIALS ENCRYPTED; NO PLAINTEXT');
    check20($cipher->decrypt($encrypted, $aad) === ['token' => $secret, 'username' => 'admin'], 'AES-256-GCM ROUNDTRIP');
    check20($encrypted !== $cipher->encrypt(['token' => $secret, 'username' => 'admin'], $aad), 'RANDOM NONCE PER ENCRYPTION');
    check20(rejects20(static fn () => $cipher->decrypt($encrypted, PanelCredentialCipher::context(54321, 'eu-1', 'marzban', 'https://vpn.example.test'))), 'CROSS-TENANT CIPHERTEXT REJECTED');
    check20(rejects20(static fn () => $cipher->decrypt($encrypted, PanelCredentialCipher::context(12345, 'us-2', 'marzban', 'https://vpn.example.test'))), 'CROSS-PANEL CIPHERTEXT REJECTED');
    check20(rejects20(static fn () => (new PanelCredentialCipher(PanelCredentialCipher::generateKey()))->decrypt($encrypted, $aad)), 'WRONG ENCRYPTION KEY REJECTED');
    $decoded = base64_decode(substr($encrypted, 3), true);
    $decoded[32] = chr(ord($decoded[32]) ^ 1);
    check20(rejects20(static fn () => $cipher->decrypt('v1:' . base64_encode($decoded), $aad)), 'TAMPERED CIPHERTEXT REJECTED');
    check20(rejects20(static fn () => new PanelCredentialCipher('some-plaintext-key')), 'WEAK KEY FORMAT REJECTED');
    check20(rejects20(static fn () => $cipher->encrypt(['token' => [1]], $aad)), 'INVALID SECRET VALUE REJECTED');
    $connection = new PanelConnection('eu-1', 'marzban', 'https://vpn.example.test', ['token' => $secret, 'another_provider_secret' => 'PASS']);
    check20($connection->exportSecretsForEncryptedStorage()['another_provider_secret'] === 'PASS', 'ARBITRARY PROVIDER SECRET FIELDS PRESERVED');
    ob_start(); var_dump($connection, $cipher); $dump = (string) ob_get_clean();
    check20(!str_contains($dump, $secret) && !str_contains($dump, 'PASS'), 'DEBUG OUTPUT REDACTS SECRETS');
    check20(rejects20(static fn () => new PanelConnection('eu-2', 'xui3', 'http://unsafe.example.test')), 'INSECURE HTTP DENIED BY DEFAULT');
    echo '[SUCCESS] STAGE 20 PANEL VAULT CRYPTO TESTS PASSED (NO DB / NETWORK)' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAILED] STAGE 20 OFFLINE TESTS' . PHP_EOL);
    exit(1);
}
