<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Container;

use RuntimeException;

final class Container
{
    private array $bindings = [];

    private array $instances = [];

    public function bind(
        string $id,
        callable $factory
    ): void {
        $this->bindings[$id] = [
            'factory' => $factory,
            'shared' => false,
        ];

        unset($this->instances[$id]);
    }

    public function singleton(
        string $id,
        callable $factory
    ): void {
        $this->bindings[$id] = [
            'factory' => $factory,
            'shared' => true,
        ];

        unset($this->instances[$id]);
    }

    public function instance(
        string $id,
        mixed $instance
    ): void {
        $this->instances[$id] = $instance;

        unset($this->bindings[$id]);
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->bindings[$id])) {
            throw new RuntimeException(
                "Service not registered: {$id}"
            );
        }

        $binding = $this->bindings[$id];

        $factory = $binding['factory'];

        $service = $factory($this);

        if ($binding['shared']) {
            $this->instances[$id] = $service;
        }

        return $service;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || isset($this->bindings[$id]);
    }
}