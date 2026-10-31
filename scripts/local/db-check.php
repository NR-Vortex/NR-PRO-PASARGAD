<?php

declare(strict_types=1);

use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Database\Connection;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$basePath = dirname(__DIR__, 2);

/*
|--------------------------------------------------------------------------
| LOAD ENVIRONMENT
|--------------------------------------------------------------------------
*/

$environment = new Environment(
    $basePath . '/.env'
);

$environment->load();

/*
|--------------------------------------------------------------------------
| DATABASE CONFIGURATION
|--------------------------------------------------------------------------
*/

$config = new Config([

    'database' => [

        'host' => $environment->get(
            'DB_HOST',
            '127.0.0.1'
        ),

        'port' => $environment->get(
            'DB_PORT',
            '5432'
        ),

        'name' => $environment->get(
            'DB_DATABASE',
            'nr_pro_pasargad'
        ),

        'username' => $environment->get(
            'DB_USERNAME'
        ),

        'password' => $environment->get(
            'DB_PASSWORD'
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

/*
|--------------------------------------------------------------------------
| DATABASE CONNECTION TEST
|--------------------------------------------------------------------------
*/

echo PHP_EOL;
echo "NR PRO PASARGAD DATABASE CHECK" . PHP_EOL;
echo "================================" . PHP_EOL;

try {

    $database = new Connection($config);

    if (!$database->ping()) {
        throw new RuntimeException(
            'Database ping failed.'
        );
    }

    $pdo = $database->pdo();

    $version = $pdo
        ->query('SHOW server_version')
        ->fetchColumn();

    echo "[OK] DATABASE CONNECTED" . PHP_EOL;
    echo "[OK] POSTGRESQL: {$version}" . PHP_EOL;

    $database->disconnect();

    exit(0);

} catch (Throwable $exception) {

    echo "[ERROR] DATABASE CONNECTION FAILED" . PHP_EOL;

    if (!extension_loaded('pdo_pgsql')) {
        echo "Reason: pdo_pgsql extension is disabled." . PHP_EOL;
    } else {
        echo "Check PostgreSQL service, database name and credentials." . PHP_EOL;
    }

    exit(1);
}