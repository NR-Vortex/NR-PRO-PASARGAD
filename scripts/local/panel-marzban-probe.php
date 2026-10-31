<?php

declare(strict_types=1);

/** Read-only live probe; run only when a real Marzban instance is available. */
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

use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\PanelDrivers;
use NRPro\Pasargad\Modules\Vpn\PanelManager;

$url = getenv('NR_PANEL_URL');
$token = getenv('NR_PANEL_TOKEN');
$username = getenv('NR_PANEL_USERNAME');
$password = getenv('NR_PANEL_PASSWORD');
if (!is_string($url) || $url === '') {
    fwrite(STDERR, "[SKIP] NR_PANEL_URL not configured. No network request sent.\n");
    exit(2);
}
$credentials = is_string($token) && $token !== ''
    ? ['token' => $token]
    : ['username' => is_string($username) ? $username : '', 'password' => is_string($password) ? $password : ''];
try {
    $connection = new PanelConnection('live-probe', 'marzban', $url, $credentials);
    $result = (new PanelManager(PanelDrivers::standard()))->ping($connection);
    echo $result->success ? "[OK] Marzban API authentication succeeded (READ ONLY).\n" : "[FAILED] Marzban API: {$result->code}\n";
    exit($result->success ? 0 : 1);
} catch (Throwable) {
    // Never print the submitted URL or credentials in exceptions.
    fwrite(STDERR, "[FAILED] Invalid Marzban connection settings.\n");
    exit(1);
}
