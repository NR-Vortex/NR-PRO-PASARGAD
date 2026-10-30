<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Bootstrap;

use NRPro\Pasargad\Core\Routing\Router;

final class Application
{
    public function __construct(
        private readonly Router $router
    ) {
    }

    public function run(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        $this->router->dispatch(
            $method,
            $uri
        );
    }
}