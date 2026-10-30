<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Routing;

use NRPro\Pasargad\Core\Http\Response;

final class Router
{
    private array $routes = [];

    public function add(
        string $method,
        string $path,
        callable $handler
    ): void {

        $method = strtoupper($method);

        $this->routes[$method][$path] = $handler;
    }

    public function get(
        string $path,
        callable $handler
    ): void {

        $this->add('GET', $path, $handler);
    }

    public function post(
        string $path,
        callable $handler
    ): void {

        $this->add('POST', $path, $handler);
    }

    public function dispatch(
        string $method,
        string $uri
    ): void {

        $method = strtoupper($method);

        $path = parse_url(
            $uri,
            PHP_URL_PATH
        ) ?: '/';

        $handler = $this->routes[$method][$path] ?? null;

        if ($handler === null) {

            Response::json([
                'success' => false,
                'message' => 'Route not found'
            ], 404);

            return;
        }

        $handler();
    }
}