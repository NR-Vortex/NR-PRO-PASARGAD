<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Http;

interface HttpTransportInterface
{
    /**
     * @param list<string> $headers
     * @throws \RuntimeException Transport failure (no URLs or credentials in exception messages).
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse;
}
