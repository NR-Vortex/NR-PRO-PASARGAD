<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Drivers;

use JsonException;
use NRPro\Pasargad\Modules\Vpn\Contracts\PanelDriverInterface;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelResult;
use NRPro\Pasargad\Modules\Vpn\Http\CurlHttpTransport;
use NRPro\Pasargad\Modules\Vpn\Http\HttpResponse;
use NRPro\Pasargad\Modules\Vpn\Http\HttpTransportInterface;
use NRPro\Pasargad\Modules\Vpn\PanelCapability;
use Throwable;

/**
 * First LIVE VPN panel adapter. All mutations are explicit, never used by /health.
 * Compatible with Marzban's /api/admin and /api/user endpoints.
 * Credentials: 'token', OR 'username' plus 'password'.
 */
final class MarzbanDriver implements PanelDriverInterface
{
    private HttpTransportInterface $transport;

    /** @var list<string> */
    private const CREATE_FIELDS = [
        'username', 'proxies', 'inbounds', 'data_limit', 'expire',
        'data_limit_reset_strategy', 'status', 'note',
    ];

    /** @var list<string> */
    private const MODIFY_FIELDS = [
        'proxies', 'inbounds', 'data_limit', 'expire',
        'data_limit_reset_strategy', 'status', 'note',
    ];

    public function __construct(?HttpTransportInterface $transport = null)
    {
        $this->transport = $transport ?? new CurlHttpTransport();
    }

    public function type(): string
    {
        return 'marzban';
    }

    public function capabilities(): array
    {
        return PanelCapability::all();
    }

    public function ping(PanelConnection $connection): PanelResult
    {
        $response = $this->request($connection, 'GET', '/api/admin');
        if ($response instanceof PanelResult) {
            return $response;
        }
        $admin = self::decodeJsonObject($response->body);
        return $admin !== null && is_string($admin['username'] ?? null)
            ? PanelResult::ok(['panel' => $connection->id, 'type' => 'marzban', 'authenticated' => true])
            : PanelResult::error('invalid_upstream_response');
    }

    public function getAccount(PanelConnection $connection, string $accountId): PanelResult
    {
        if (!self::validUsername($accountId)) {
            return PanelResult::error('invalid_account_id');
        }
        $response = $this->request($connection, 'GET', '/api/user/' . rawurlencode($accountId));
        return $this->userResult($connection, $response);
    }

    public function createAccount(PanelConnection $connection, array $specification): PanelResult
    {
        if (!self::validPayload($specification, true)) {
            return PanelResult::error('invalid_specification');
        }
        return $this->userResult($connection, $this->request($connection, 'POST', '/api/user', $specification));
    }

    public function updateAccount(PanelConnection $connection, string $accountId, array $changes): PanelResult
    {
        if (!self::validUsername($accountId)) {
            return PanelResult::error('invalid_account_id');
        }
        if (!self::validPayload($changes, false)) {
            return PanelResult::error('invalid_changes');
        }
        return $this->userResult(
            $connection,
            $this->request($connection, 'PUT', '/api/user/' . rawurlencode($accountId), $changes)
        );
    }

    public function deleteAccount(PanelConnection $connection, string $accountId): PanelResult
    {
        if (!self::validUsername($accountId)) {
            return PanelResult::error('invalid_account_id');
        }
        $response = $this->request($connection, 'DELETE', '/api/user/' . rawurlencode($accountId));
        if ($response instanceof PanelResult) {
            return $response;
        }
        return PanelResult::ok(['panel' => $connection->id, 'username' => $accountId, 'deleted' => true]);
    }

    /** @param array<string,mixed>|null $payload */
    private function request(
        PanelConnection $connection,
        string $method,
        string $path,
        ?array $payload = null
    ): HttpResponse|PanelResult {
        if ($connection->type !== 'marzban') {
            return PanelResult::error('panel_type_mismatch');
        }
        $base = rtrim($connection->baseUrl, '/');
        try {
            $token = $this->accessToken($connection, $base);
            if ($token instanceof PanelResult) {
                return $token;
            }
            $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
            $body = null;
            if ($payload !== null) {
                // Marzban expects maps ({}), never empty JSON lists ([]), for proxy specs.
                if (isset($payload['proxies'])) {
                    foreach ($payload['proxies'] as $protocol => $settings) {
                        if ($settings === []) {
                            $payload['proxies'][$protocol] = new \stdClass();
                        }
                    }
                }
                if (($payload['inbounds'] ?? null) === []) {
                    $payload['inbounds'] = new \stdClass();
                }
                $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $headers[] = 'Content-Type: application/json';
            }
            $response = $this->transport->send($method, $base . $path, $headers, $body);
            if ($response->status < 200 || $response->status >= 300) {
                return PanelResult::error(self::statusCode($response->status));
            }
            return $response;
        } catch (Throwable) {
            // No raw URLs, headers, credentials, API response or cURL traces escape.
            return PanelResult::error('transport_error');
        }
    }

    private function accessToken(PanelConnection $connection, string $base): string|PanelResult
    {
        $directToken = $connection->credential('token');
        if ($directToken !== null && $directToken !== '') {
            return self::validHeaderValue($directToken)
                ? $directToken
                : PanelResult::error('invalid_credentials');
        }
        $username = $connection->credential('username');
        $password = $connection->credential('password');
        if ($username === null || $username === '' || $password === null || $password === '') {
            return PanelResult::error('missing_credentials');
        }
        if (strlen($username) > 256 || strlen($password) > 2048) {
            return PanelResult::error('invalid_credentials');
        }
        $response = $this->transport->send(
            'POST',
            $base . '/api/admin/token',
            ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['username' => $username, 'password' => $password], '', '&', PHP_QUERY_RFC3986)
        );
        if ($response->status < 200 || $response->status >= 300) {
            return PanelResult::error($response->status === 401 || $response->status === 400
                ? 'authentication_failed'
                : self::statusCode($response->status));
        }
        $data = self::decodeJsonObject($response->body);
        if ($data === null || !is_string($data['access_token'] ?? null)
            || !self::validHeaderValue($data['access_token'])) {
            return PanelResult::error('invalid_auth_response');
        }
        return $data['access_token'];
    }

    private function userResult(PanelConnection $connection, HttpResponse|PanelResult $response): PanelResult
    {
        if ($response instanceof PanelResult) {
            return $response;
        }
        $user = self::decodeJsonObject($response->body);
        if ($user === null || !is_string($user['username'] ?? null)) {
            return PanelResult::error('invalid_upstream_response');
        }
        // Subscription links are sensitive and must only be shown to their owner.
        return PanelResult::ok([
            'panel' => $connection->id,
            'account' => [
                'username' => $user['username'],
                'status' => $user['status'] ?? null,
                'data_limit_bytes' => $user['data_limit'] ?? null,
                'used_traffic_bytes' => $user['used_traffic'] ?? null,
                'expire_at' => $user['expire'] ?? null,
                'subscription_url' => $user['subscription_url'] ?? null,
                'links' => $user['links'] ?? [],
            ],
        ]);
    }

    private static function decodeJsonObject(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) && !array_is_list($decoded) ? $decoded : null;
        } catch (JsonException) {
            return null;
        }
    }

    private static function validHeaderValue(string $value): bool
    {
        return $value !== '' && strlen($value) <= 8192 && !preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    private static function validUsername(string $username): bool
    {
        return preg_match('/^[a-z0-9_]{3,32}$/', $username) === 1;
    }

    /** @param array<string,mixed> $payload */
    private static function validPayload(array $payload, bool $create): bool
    {
        $allowed = $create ? self::CREATE_FIELDS : self::MODIFY_FIELDS;
        if ($payload === [] || array_is_list($payload)) {
            return false;
        }
        foreach ($payload as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return false;
            }
            if ($key === 'username' && (!is_string($value) || !self::validUsername($value))) {
                return false;
            }
            if (in_array($key, ['data_limit', 'expire'], true) && (!is_int($value) || $value < 0)) {
                return false;
            }
            if ($key === 'status' && (!is_string($value)
                || !in_array($value, ['active', 'disabled', 'on_hold'], true))) {
                return false;
            }
            if ($key === 'data_limit_reset_strategy' && (!is_string($value)
                || !in_array($value, ['no_reset', 'day', 'week', 'month', 'year'], true))) {
                return false;
            }
            if ($key === 'note' && $value !== null && (!is_string($value) || strlen($value) > 4096)) {
                return false;
            }
            if ($key === 'proxies') {
                if (!is_array($value) || $value === [] || array_is_list($value)) {
                    return false;
                }
                foreach ($value as $protocol => $config) {
                    if (!is_string($protocol) || preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $protocol) !== 1
                        || !is_array($config)) {
                        return false;
                    }
                }
            }
            if ($key === 'inbounds') {
                if (!is_array($value)) {
                    return false;
                }
                foreach ($value as $protocol => $tags) {
                    if (!is_string($protocol) || !is_array($tags)) {
                        return false;
                    }
                    foreach ($tags as $tag) {
                        if (!is_string($tag) || strlen($tag) > 256) {
                            return false;
                        }
                    }
                }
            }
        }
        return !$create || (isset($payload['username'], $payload['proxies']));
    }

    private static function statusCode(int $status): string
    {
        return match ($status) {
            401 => 'authentication_failed',
            403 => 'permission_denied',
            404 => 'account_not_found',
            409 => 'account_already_exists',
            400, 422 => 'invalid_upstream_request',
            408, 429, 502, 503, 504 => 'upstream_unavailable',
            default => $status >= 500 ? 'upstream_error' : 'unexpected_upstream_status',
        };
    }
}
