<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\DTO;

final class PanelResult
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly bool $success,
        public readonly string $code,
        public readonly array $data
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function ok(array $data = []): self
    {
        return new self(true, 'ok', $data);
    }

    public static function unsupported(string $capability): self
    {
        return new self(false, 'unsupported_capability', ['capability' => $capability]);
    }

    public static function error(string $code): self
    {
        return new self(false, $code, []);
    }
}
