<?php

declare(strict_types=1);

/**
 * NR PRO PASARGAD
 * Application Bootstrap
 * Version: 0.1.0
 */

use NRPro\Pasargad\Core\Bootstrap\Application;
use NRPro\Pasargad\Core\Cache\RedisManager;
use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Core\Config\Environment;
use NRPro\Pasargad\Core\Container\Container;
use NRPro\Pasargad\Core\Database\Connection;
use NRPro\Pasargad\Core\Exceptions\Handler;
use NRPro\Pasargad\Core\Http\Response;
use NRPro\Pasargad\Core\Logging\Logger;
use NRPro\Pasargad\Core\Queue\RedisQueue;
use NRPro\Pasargad\Integrations\Telegram\Client\TelegramClient;
use NRPro\Pasargad\Core\Routing\Router;

/*
|--------------------------------------------------------------------------
| PROJECT ROOT AND AUTOLOAD
|--------------------------------------------------------------------------
*/

$basePath = dirname(__DIR__);

require_once $basePath . '/vendor/autoload.php';

/*
|--------------------------------------------------------------------------
| ENVIRONMENT
|--------------------------------------------------------------------------
*/

$environment = new Environment($basePath . '/.env');
$environment->load();

/*
|--------------------------------------------------------------------------
| APPLICATION CONFIGURATION
|--------------------------------------------------------------------------
*/

$config = new Config([
    'app' => [
        'name' => $environment->get('APP_NAME', 'NR PRO PASARGAD'),
        'environment' => $environment->get('APP_ENV', 'production'),
        'debug' => $environment->boolean('APP_DEBUG', false),
        'url' => $environment->get('APP_URL', 'http://127.0.0.1:8000'),
        'version' => '0.1.0',
        'timezone' => 'Asia/Tehran',
    ],

    'telegram' => [
        'bot_token' => $environment->get('TELEGRAM_BOT_TOKEN'),
        'admin_id' => $environment->get('TELEGRAM_ADMIN_ID'),
        'webhook_secret' => $environment->get('TELEGRAM_WEBHOOK_SECRET', ''),
    ],

    'pasarguard' => [
        'base_url' => $environment->get('PASARGUARD_BASE_URL'),
        'api_token' => $environment->get('PASARGUARD_API_TOKEN'),
    ],

    'database' => [
        'driver' => 'pgsql',
        'host' => $environment->get('DB_HOST', '127.0.0.1'),
        'port' => $environment->get('DB_PORT', '5432'),
        'name' => $environment->get('DB_DATABASE', 'nr_pro_pasargad'),
        'username' => $environment->get('DB_USERNAME'),
        'password' => $environment->get('DB_PASSWORD'),
        'sslmode' => $environment->get('DB_SSLMODE', 'disable'),
        'connect_timeout' => $environment->get('DB_CONNECT_TIMEOUT', '5'),
    ],

    'redis' => [
        'host' => $environment->get('REDIS_HOST', '127.0.0.1'),
        'port' => $environment->get('REDIS_PORT', '6380'),
        'database' => $environment->get('REDIS_DATABASE', '0'),
        'password' => $environment->get('REDIS_PASSWORD', ''),
        'timeout' => $environment->get('REDIS_TIMEOUT', '3'),
    ],

    'queue' => [
        'name' => $environment->get('QUEUE_NAME', 'default'),
    ],
]);

/*
|--------------------------------------------------------------------------
| DEPENDENCY CONTAINER
|--------------------------------------------------------------------------
*/

$container = new Container();

$container->instance(Config::class, $config);
$container->instance(Environment::class, $environment);

$container->singleton(
    Connection::class,
    static function (Container $container): Connection {
        return new Connection($container->get(Config::class));
    }
);

$container->singleton(
    RedisManager::class,
    static function (Container $container): RedisManager {
        return new RedisManager($container->get(Config::class));
    }
);

$container->singleton(
    RedisQueue::class,
    static function (Container $container): RedisQueue {
        return new RedisQueue($container->get(Config::class));
    }
);

$container->singleton(
    TelegramClient::class,
    static function (Container $container): TelegramClient {
        return new TelegramClient($container->get(Config::class));
    }
);

$container->singleton(
    Logger::class,
    static function (Container $container) use ($basePath): Logger {
        return new Logger($basePath . '/storage/logs/app.log');
    }
);

$container->singleton(
    Router::class,
    static function (Container $container): Router {
        return new Router();
    }
);

$container->singleton(
    Handler::class,
    static function (Container $container): Handler {
        return new Handler($container->get(Logger::class));
    }
);

/*
|--------------------------------------------------------------------------
| INITIALIZE APPLICATION SERVICES
|--------------------------------------------------------------------------
*/

$errorHandler = $container->get(Handler::class);
$errorHandler->register();

$logger = $container->get(Logger::class);
$logger->info('NR PRO PASARGAD application initialized', [
    'version' => $config->get('app.version'),
    'environment' => $config->get('app.environment'),
]);

$router = $container->get(Router::class);

/*
|--------------------------------------------------------------------------
| HEALTH CHECK
|--------------------------------------------------------------------------
*/

$router->get('/health', static function () use ($config): void {
    Response::json([
        'success' => true,
        'project' => $config->get('app.name'),
        'status' => 'running',
        'version' => $config->get('app.version'),
    ]);
});

/*
|--------------------------------------------------------------------------
| CREATE AND RETURN APPLICATION
|--------------------------------------------------------------------------
*/

$app = new Application($router);

return $app;
