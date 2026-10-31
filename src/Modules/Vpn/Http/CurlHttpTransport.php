<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Http;

use RuntimeException;

/**
 * Restrictive HTTP transport: verified TLS, no redirects, short timeouts,
 * capped response size, no credential-bearing exception messages.
 */
final class CurlHttpTransport implements HttpTransportInterface
{
    private const MAX_BODY_BYTES = 2_097_152;

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('HTTP transport unavailable (PHP curl extension missing).');
        }
        if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) {
            throw new RuntimeException('Unsupported HTTP method.');
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Unsupported HTTP URL scheme.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('HTTP transport initialization failed.');
        }
        $responseBody = '';
        $tooLarge = false;
        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'NR-PRO-BOT/Stage18',
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$responseBody, &$tooLarge): int {
                    if (strlen($responseBody) + strlen($chunk) > self::MAX_BODY_BYTES) {
                        $tooLarge = true;
                        return 0;
                    }
                    $responseBody .= $chunk;
                    return strlen($chunk);
                },
            ];
            // Prevent protocol switching through redirects (which are disabled above).
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                $options[CURLOPT_PROTOCOLS_STR] = $scheme;
            } else {
                $options[CURLOPT_PROTOCOLS] = $scheme === 'https' ? CURLPROTO_HTTPS : CURLPROTO_HTTP;
            }
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException('HTTP transport setup failed.');
            }
            $succeeded = curl_exec($handle);
            if ($succeeded === false) {
                throw new RuntimeException($tooLarge ? 'HTTP response size limit exceeded.' : 'HTTP transport request failed.');
            }
            return new HttpResponse((int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseBody);
        } finally {
            curl_close($handle);
        }
    }
}
