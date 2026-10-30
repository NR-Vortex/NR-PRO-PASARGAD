<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Config;

final class Config
{
    public function __construct(
        private readonly array $items = []
    ) {
    }

    public function get(
        string $key,
        mixed $default = null
    ): mixed {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {

            if (
                !is_array($value) ||
                !array_key_exists($segment, $value)
            ) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();

        return $this->get($key, $sentinel) !== $sentinel;
    }
}