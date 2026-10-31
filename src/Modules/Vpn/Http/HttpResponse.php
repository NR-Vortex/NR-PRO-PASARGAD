<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Http;

/** Value object used by the driver and by offline mock transports. */
final class HttpResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body
    ) {
    }
}
