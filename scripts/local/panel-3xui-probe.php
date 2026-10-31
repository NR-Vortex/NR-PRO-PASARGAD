<?php

declare(strict_types=1);

/** Read-only remote 3x-ui API auth probe. Does not print tokens or account contents. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
$project = dirname(__DIR__, 2);
if (is_file($project . '/vendor/autoload.php')) {
    require $project . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($project): void {
        $prefix = 'NRPro\\Pasargad\\';
        if (str_starts_with($class, $prefix)) {
            $file = $project . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) { require $file; }
        }
    });
}

use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\Drivers\ThreeXuiDriver;

$url = trim((string) getenv('NR_XUI_URL'));
$token = (string) getenv('NR_XUI_TOKEN');
if ($url === '' || $token === '') {
    fwrite(STDERR, "[CONFIG REQUIRED] Set NR_XUI_URL and NR_XUI_TOKEN only in your local terminal.\n");
    exit(2);
}
try {
    $connection = new PanelConnection('probe-only', 'xui3', $url, ['token' => $token]);
    $result = (new ThreeXuiDriver())->ping($connection);
    if (!$result->success) {
        fwrite(STDERR, '[FAILED] Modern 3x-ui API ping: ' . $result->code . PHP_EOL);
        exit(1);
    }
    echo '[SUCCESS] Modern 3x-ui API reachable and authenticated (read only).' . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "[FAILED] Invalid configuration or transport initialization error.\n");
    exit(1);
}
