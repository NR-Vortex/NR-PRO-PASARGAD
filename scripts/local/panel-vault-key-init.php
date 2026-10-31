<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
require_once dirname(__DIR__, 2) . '/src/Modules/Vpn/Security/PanelCredentialCipher.php';

use NRPro\Pasargad\Modules\Vpn\Security\PanelCredentialCipher;

$root = dirname(__DIR__, 2);
$env = $root . '/.env';
if (!is_file($env)) {
    fwrite(STDERR, '[ERROR] Project .env missing; create it using your existing local setup first.' . PHP_EOL);
    exit(1);
}
if (getenv('NR_PANEL_ENCRYPTION_KEY') !== false) {
    echo '[OK] Encryption key already available in process environment. No file changed.' . PHP_EOL;
    exit(0);
}
$git = $root . '/.git';
if (file_exists($git)) {
    $out = [];
    $code = 1;
    $cmd = 'git -C ' . escapeshellarg($root) . ' ls-files --error-unmatch -- .env 2>&1';
    @exec($cmd, $out, $code);
    if ($code === 0) {
        fwrite(STDERR, '[ERROR] .env is tracked by Git. Refusing to write encryption key.' . PHP_EOL);
        exit(1);
    }
    $ignoreCode = 1;
    @exec('git -C ' . escapeshellarg($root) . ' check-ignore -q -- .env 2>&1', $out, $ignoreCode);
    if ($ignoreCode !== 0) {
        fwrite(STDERR, '[ERROR] .env is NOT ignored by Git. Add a safe ignore rule before creating secrets.' . PHP_EOL);
        exit(1);
    }
}
$handle = fopen($env, 'c+');
if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
    fwrite(STDERR, '[ERROR] Could not exclusively lock .env.' . PHP_EOL);
    exit(1);
}
try {
    rewind($handle);
    $content = stream_get_contents($handle);
    if (!is_string($content)) { throw new RuntimeException('Cannot read .env'); }
    if (preg_match('/^\s*NR_PANEL_ENCRYPTION_KEY\s*=/m', $content)) {
        echo '[OK] Encryption key already present in .env; no changes.' . PHP_EOL;
        exit(0);
    }
    $key = PanelCredentialCipher::generateKey();
    $addition = (str_ends_with($content, "\n") || $content === '' ? '' : "\n") .
        'NR_PANEL_ENCRYPTION_KEY=' . $key . "\n";
    fseek($handle, 0, SEEK_END);
    if (fwrite($handle, $addition) !== strlen($addition) || !fflush($handle)) {
        throw new RuntimeException('Cannot append key to .env');
    }
    if (DIRECTORY_SEPARATOR === '/') { @chmod($env, 0600); }
    echo '[OK] Unique 256-bit panel encryption key saved privately to .env; value hidden.' . PHP_EOL;
    echo '[IMPORTANT] Back up the key securely. Losing it makes stored panel credentials unreadable.' . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, '[ERROR] Could not initialize panel encryption key.' . PHP_EOL);
    exit(1);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
