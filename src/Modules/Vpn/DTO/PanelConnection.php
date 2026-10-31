<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\DTO;

use InvalidArgumentException;

/**
 * Represents one configured panel. Credentials are ephemeral and MUST NOT be logged.
 * Persistent storage and encryption will be added in a separate migration.
 */
final class PanelConnection
{
    /** @param array<string, string> $credentials */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $baseUrl,
        private readonly array $credentials = [],
        bool $allowInsecureHttp = false
    ) {
        if (trim($id) === '' || !preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/i', $id)) {
            throw new InvalidArgumentException('Invalid panel identifier.');
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $type)) {
            throw new InvalidArgumentException('Invalid panel type.');
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Invalid panel base URL.');
        }
        $parts = parse_url($baseUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidArgumentException('Invalid panel base URL.');
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && !($allowInsecureHttp && $scheme === 'http')) {
            throw new InvalidArgumentException('Panel connections require HTTPS unless explicitly allowed.');
        }
        foreach (['user', 'pass', 'query', 'fragment'] as $forbidden) {
            if (array_key_exists($forbidden, $parts)) {
                throw new InvalidArgumentException('Credentials, query strings and fragments are forbidden in panel URLs.');
            }
        }
        foreach ($credentials as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidArgumentException('Credentials must have string keys and values.');
            }
        }
    }

    /** Drivers may retrieve credentials, but callers must never log the returned values. */
    public function credential(string $key): ?string
    {
        return $this->credentials[$key] ?? null;
    }

    /** @internal Sensitive: only encrypted repositories may call this; never log or serialize.
     * @return array<string,string>
     */
    public function exportSecretsForEncryptedStorage(): array
    {
        return $this->credentials;
    }

    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'baseUrl' => $this->baseUrl,
            'credentials' => '[REDACTED]',
        ];
    }
}
