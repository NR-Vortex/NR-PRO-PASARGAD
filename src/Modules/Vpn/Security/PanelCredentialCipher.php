<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Security;

use InvalidArgumentException;
use RuntimeException;

/** Authenticated at-rest encryption; the ciphertext is bound to tenant and panel identity. */
final class PanelCredentialCipher
{
    private readonly string $key;

    public function __construct(string $encodedKey)
    {
        if (!str_starts_with($encodedKey, 'base64:')) {
            throw new InvalidArgumentException('Panel encryption key must use base64: format.');
        }
        $key = base64_decode(substr($encodedKey, 7), true);
        if ($key === false || strlen($key) !== 32) {
            throw new InvalidArgumentException('Panel encryption key must contain 32 random bytes.');
        }
        $this->key = $key;
        if (!extension_loaded('openssl')) {
            throw new RuntimeException('OpenSSL extension is required for encrypted panel storage.');
        }
    }

    /** @param array<string,string> $credentials */
    public function encrypt(array $credentials, string $context): string
    {
        self::assertContext($context);
        self::assertCredentials($credentials);
        ksort($credentials, SORT_STRING);
        $plaintext = json_encode($credentials, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($plaintext) > 16_384) {
            throw new InvalidArgumentException('Panel credential payload too large.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key,
            OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if ($encrypted === false || strlen($tag) !== 16) {
            throw new RuntimeException('Panel credential encryption failed.');
        }
        return 'v1:' . base64_encode($nonce . $tag . $encrypted);
    }

    /** @return array<string,string> */
    public function decrypt(string $payload, string $context): array
    {
        self::assertContext($context);
        if (!str_starts_with($payload, 'v1:') || strlen($payload) > 25_000) {
            throw new RuntimeException('Unable to decrypt panel credentials.');
        }
        $blob = base64_decode(substr($payload, 3), true);
        if ($blob === false || strlen($blob) < 30) {
            throw new RuntimeException('Unable to decrypt panel credentials.');
        }
        $plaintext = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $this->key,
            OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16), $context);
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt panel credentials.');
        }
        try {
            $data = json_decode($plaintext, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new InvalidArgumentException('Invalid credential payload.');
            }
            self::assertCredentials($data);
            return $data;
        } catch (\Throwable) {
            throw new RuntimeException('Unable to decrypt panel credentials.');
        }
    }

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /** Keep context deterministic and scoped to tenant, id, type and remote URL. */
    public static function context(int $ownerId, string $panelId, string $type, string $url): string
    {
        if ($ownerId <= 0 || $panelId === '' || $type === '' || $url === '') {
            throw new InvalidArgumentException('Invalid panel encryption context.');
        }
        return json_encode([$ownerId, $panelId, $type, $url], JSON_THROW_ON_ERROR);
    }

    private static function assertContext(string $context): void
    {
        if ($context === '' || strlen($context) > 2_048) {
            throw new InvalidArgumentException('Invalid panel encryption context.');
        }
    }

    /** @param array<mixed,mixed> $credentials */
    private static function assertCredentials(array $credentials): void
    {
        if (count($credentials) > 32) {
            throw new InvalidArgumentException('Too many panel credential fields.');
        }
        foreach ($credentials as $field => $value) {
            if (!is_string($field) || $field === '' || strlen($field) > 60
                || !preg_match('/^[a-z][a-z0-9_]*$/', $field)
                || !is_string($value) || strlen($value) > 4_096) {
                throw new InvalidArgumentException('Invalid panel credential field.');
            }
        }
    }

    public function __debugInfo(): array
    {
        return ['key' => '[REDACTED]'];
    }
}
