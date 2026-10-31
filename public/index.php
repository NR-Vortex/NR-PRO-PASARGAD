<?php

declare(strict_types=1);

use NRPro\Pasargad\Core\Bootstrap\Application;
use NRPro\Pasargad\Core\Config\Config;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramUpdateParser;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramWebhookInbox;
use NRPro\Pasargad\Integrations\Telegram\Webhook\TelegramWebhookReceiver;

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/telegram/webhook') {
    /** @var Application $app */
    $app = require dirname(__DIR__) . '/bootstrap/app.php';

    // Environment and Composer autoload are initialized by the bootstrap.
    $receiver = new TelegramWebhookReceiver(
        $container->get(Config::class),
        new TelegramUpdateParser(),
        new TelegramWebhookInbox($container->get(Config::class)),
    );

    // Limit input memory and reject oversized bodies. Never echo request bodies.
    $input = fopen('php://input', 'rb');
    $body = $input !== false ? stream_get_contents($input, 65537) : false;
    if ($input !== false) {
        fclose($input);
    }
    if (!is_string($body)) {
        $body = '';
    }

    $result = $receiver->receive(
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
        (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''),
        $body,
    );

    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if ($result['status'] === 405) {
        header('Allow: POST');
    }
    echo json_encode([
        'ok' => $result['status'] === 200,
        'status' => $result['code'],
    ], JSON_THROW_ON_ERROR);
    exit;
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->run();
