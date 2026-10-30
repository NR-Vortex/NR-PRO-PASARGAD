<?php

declare(strict_types=1);

use NRPro\Pasargad\Core\Bootstrap\Application;
use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Http\Response;
use NRPro\Pasargad\Core\Routing\Router;

/*
|--------------------------------------------------------------------------
| AUTOLOAD
|--------------------------------------------------------------------------
*/

require_once dirname(__DIR__) . '/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| ENVIRONMENT
|--------------------------------------------------------------------------
*/

$environment = new Environment(
    dirname(__DIR__) . '/.env'
);

$environment->load();

/*
|--------------------------------------------------------------------------
| APPLICATION CONFIGURATION
|--------------------------------------------------------------------------
*/

$config = new Config([

    'app' => [

        'name' => $environment->get(
            'APP_NAME',
            'NR PRO PASARGAD'
        ),

        'environment' => $environment->get(
            'APP_ENV',
            'production'
        ),

        'debug' => $environment->boolean(
            'APP_DEBUG',
            false
        ),

        'url' => $environment->get(
            'APP_URL',
            'http://127.0.0.1:8000'
        ),

        'version' => '0.1.0',

    ],

    'telegram' => [

        'bot_token' => $environment->get(
            'TELEGRAM_BOT_TOKEN'
        ),

        'admin_id' => $environment->get(
            'TELEGRAM_ADMIN_ID'
        ),

    ],

    'pasarguard' => [

        'base_url' => $environment->get(
            'PASARGUARD_BASE_URL'
        ),

        'api_token' => $environment->get(
            'PASARGUARD_API_TOKEN'
        ),

    ],

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

    ],

]);

/*
|--------------------------------------------------------------------------
| ROUTER
|--------------------------------------------------------------------------
*/

$router = new Router();

/*
|--------------------------------------------------------------------------
| HEALTH ROUTE
|--------------------------------------------------------------------------
*/

$router->get(
    '/health',
    static function () use ($config): void {

        Response::json([

            'success' => true,

            'project' => $config->get('app.name'),

            'status' => 'running',

            'version' => $config->get('app.version'),

        ]);
    }
);

/*
|--------------------------------------------------------------------------
| APPLICATION
|--------------------------------------------------------------------------
*/

return new Application($router);