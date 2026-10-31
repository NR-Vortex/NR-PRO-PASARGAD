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
 * Modern 3x-ui adapter (client-first API: /panel/api/clients/*).
 * Requires HTTPS and a scoped bearer token. It does not use browser-session cookies.
 * Deliberately NOT compatible with older 3x-ui versions without /clients endpoints.
 * A PanelConnection represents ONE 3x-ui instance; client IDs are emails, not inbound IDs.
 */
final class ThreeXuiDriver implements PanelDriverInterface
{
    private const ROOT = '/panel/api/clients';
    private const CHANGE_FIELDS = ['total_bytes', 'expire_ms', 'enable', 'limit_ip', 'comment'];
    private const CREATE_FIELDS = ['email', 'inbound_ids', 'total_bytes', 'expire_ms', 'enable', 'limit_ip', 'comment'];
    // Preserve accepted client metadata when performing partial updates; NEVER send traffic counters.
    private const PERSISTED_FIELDS = [
        'email', 'subId', 'uuid', 'password', 'auth', 'flow', 'totalGB',
        'expiryTime', 'limitIp', 'limitHwid', 'tgId', 'comment', 'enable',
        'security', 'secret', 'adTag', 'privateKey', 'publicKey',
        'preSharedKey', 'allowedIPs', 'keepAlive', 'group', 'reset',
        'resetDay', 'resetWeekday', 'resetMax', 'trafficReset', 'trafficResetDay',
    ];

    private HttpTransportInterface $transport;

    public function __construct(?HttpTransportInterface $transport = null)
    {
        $this->transport = $transport ?? new CurlHttpTransport();
    }

    public function type(): string
    {
        return 'xui3';
    }

    public function capabilities(): array
    {
        return PanelCapability::all();
    }

    public function ping(PanelConnection $connection): PanelResult
    {
        $response = $this->request($connection, 'GET', self::ROOT . '/list/paged?pageSize=1');
        if ($response instanceof PanelResult) {
            return $response;
        }
        $envelope = self::envelope($response->body);
        return $envelope !== null && isset($envelope['obj']) && is_array($envelope['obj'])
            && isset($envelope['obj']['items']) && is_array($envelope['obj']['items'])
            ? PanelResult::ok(['panel' => $connection->id, 'type' => $this->type(), 'authenticated' => true])
            : PanelResult::error('invalid_upstream_response');
    }

    public function getAccount(PanelConnection $connection, string $accountId): PanelResult
    {
        if (!self::validEmail($accountId)) {
            return PanelResult::error('invalid_account_id');
        }
        $result = $this->fetchClient($connection, $accountId);
        if ($result instanceof PanelResult) {
            return $result;
        }
        return PanelResult::ok(['panel' => $connection->id, 'account' => self::normalize($result)]);
    }

    public function createAccount(PanelConnection $connection, array $specification): PanelResult
    {
        if (!self::validCreate($specification)) {
            return PanelResult::error('invalid_specification');
        }
        $client = ['email' => $specification['email']];
        self::applyChanges($client, $specification);
        $client += ['totalGB' => 0, 'expiryTime' => 0, 'enable' => true, 'limitIp' => 0];
        $response = $this->request($connection, 'POST', self::ROOT . '/add', [
            'client' => $client,
            'inboundIds' => array_values($specification['inbound_ids']),
        ]);
        if ($response instanceof PanelResult) {
            return $response;
        }
        $result = self::envelope($response->body);
        return $result === null ? PanelResult::error('invalid_upstream_response')
            : PanelResult::ok(['panel' => $connection->id, 'username' => $specification['email'], 'created' => true]);
    }

    public function updateAccount(PanelConnection $connection, string $accountId, array $changes): PanelResult
    {
        if (!self::validEmail($accountId)) {
            return PanelResult::error('invalid_account_id');
        }
        if (!self::validChanges($changes)) {
            return PanelResult::error('invalid_changes');
        }
        // Current API replaces the client row; first read and preserve protocol settings.
        $existing = $this->fetchClient($connection, $accountId);
        if ($existing instanceof PanelResult) {
            return $existing;
        }
        $payload = array_intersect_key($existing, array_flip(self::PERSISTED_FIELDS));
        $payload['email'] = $accountId;
        self::applyChanges($payload, $changes);
        $response = $this->request($connection, 'POST', self::ROOT . '/update/' . rawurlencode($accountId), $payload);
        if ($response instanceof PanelResult) {
            return $response;
        }
        $result = self::envelope($response->body);
        if ($result === null) {
            return PanelResult::error('invalid_upstream_response');
        }
        return PanelResult::ok(['panel' => $connection->id, 'username' => $accountId, 'updated' => true]);
    }

    public function deleteAccount(PanelConnection $connection, string $accountId): PanelResult
    {
        if (!self::validEmail($accountId)) {
            return PanelResult::error('invalid_account_id');
        }
        // Never delete an entire inbound. keepTraffic=1 preserves accounting history.
        $response = $this->request(
            $connection, 'POST', self::ROOT . '/del/' . rawurlencode($accountId) . '?keepTraffic=1'
        );
        if ($response instanceof PanelResult) {
            return $response;
        }
        return self::envelope($response->body) === null
            ? PanelResult::error('invalid_upstream_response')
            : PanelResult::ok(['panel' => $connection->id, 'username' => $accountId, 'deleted' => true]);
    }

    /** @return array<string, mixed>|PanelResult */
    private function fetchClient(PanelConnection $connection, string $email): array|PanelResult
    {
        $response = $this->request($connection, 'GET', self::ROOT . '/get/' . rawurlencode($email));
        if ($response instanceof PanelResult) {
            return $response;
        }
        $envelope = self::envelope($response->body);
        if ($envelope === null || !isset($envelope['obj']) || !is_array($envelope['obj'])) {
            return PanelResult::error('invalid_upstream_response');
        }
        $obj = $envelope['obj'];
        // Both {obj: {client: {...}, inboundIds: [...]}} and {obj: {...client fields}} occur.
        $client = isset($obj['client']) && is_array($obj['client']) ? $obj['client'] : $obj;
        if (!is_string($client['email'] ?? null) || !hash_equals($email, $client['email'])) {
            return PanelResult::error('invalid_upstream_response');
        }
        if (isset($obj['inboundIds']) && is_array($obj['inboundIds'])) {
            $client['inboundIds'] = $obj['inboundIds'];
        }
        if (isset($obj['usedTraffic']) && is_int($obj['usedTraffic'])) {
            $client['usedTraffic'] = $obj['usedTraffic'];
        } elseif (isset($obj['traffic']) && is_array($obj['traffic'])) {
            $client['usedTraffic'] = (int) ($obj['traffic']['up'] ?? 0) + (int) ($obj['traffic']['down'] ?? 0);
        }
        return $client;
    }

    /** @return array<string,mixed> */
    private static function normalize(array $client): array
    {
        // Do not expose UUID, passwords, auth secrets, tokens or raw upstream response.
        return [
            'username' => $client['email'],
            'status' => ($client['enable'] ?? false) === true ? 'active' : 'disabled',
            'data_limit_bytes' => $client['totalGB'] ?? null,
            'used_traffic_bytes' => $client['usedTraffic'] ?? null,
            'expire_at' => $client['expiryTime'] ?? null, // 3x-ui uses MILLISECONDS, not seconds
            'inbound_ids' => $client['inboundIds'] ?? [],
        ];
    }

    /** @param array<string,mixed> $spec */
    private static function validCreate(array $spec): bool
    {
        if (!self::validFields($spec, self::CREATE_FIELDS)
            || !isset($spec['email'], $spec['inbound_ids'])
            || !array_key_exists('total_bytes', $spec) || !array_key_exists('expire_ms', $spec)
            || !self::validEmail($spec['email'])
            || !is_array($spec['inbound_ids']) || !array_is_list($spec['inbound_ids'])
            || count($spec['inbound_ids']) < 1 || count($spec['inbound_ids']) > 20) {
            return false;
        }
        $unique = [];
        foreach ($spec['inbound_ids'] as $id) {
            if (!is_int($id) || $id < 1 || $id > 2_147_483_647 || isset($unique[$id])) {
                return false;
            }
            $unique[$id] = true;
        }
        $changes = array_diff_key($spec, ['email' => true, 'inbound_ids' => true]);
        return $changes === [] || self::validChanges($changes);
    }

    /** @param array<string,mixed> $spec */
    private static function validChanges(array $spec): bool
    {
        if (!self::validFields($spec, self::CHANGE_FIELDS)) {
            return false;
        }
        foreach ($spec as $key => $value) {
            if (in_array($key, ['total_bytes', 'expire_ms'], true) && (!is_int($value) || $value < 0)) {
                return false;
            }
            if ($key === 'enable' && !is_bool($value)) {
                return false;
            }
            if ($key === 'limit_ip' && (!is_int($value) || $value < 0 || $value > 1000)) {
                return false;
            }
            if ($key === 'comment' && (!is_string($value) || strlen($value) > 1000)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $spec @param list<string> $allowed */
    private static function validFields(array $spec, array $allowed): bool
    {
        if ($spec === [] || array_is_list($spec)) {
            return false;
        }
        foreach ($spec as $key => $unused) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $client @param array<string,mixed> $changes */
    private static function applyChanges(array &$client, array $changes): void
    {
        $map = ['total_bytes' => 'totalGB', 'expire_ms' => 'expiryTime',
            'enable' => 'enable', 'limit_ip' => 'limitIp', 'comment' => 'comment'];
        foreach ($map as $input => $remote) {
            if (array_key_exists($input, $changes)) {
                $client[$remote] = $changes[$input];
            }
        }
    }

    private static function validEmail(mixed $email): bool
    {
        // Compatible with common 3x-ui client emails (not necessarily RFC email addresses).
        return is_string($email) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._@-]{0,127}$/D', $email) === 1
            && !str_contains($email, '..');
    }

    /** @param array<string,mixed>|null $body */
    private function request(
        PanelConnection $connection, string $method, string $path, ?array $body = null
    ): HttpResponse|PanelResult {
        if ($connection->type !== 'xui3') {
            return PanelResult::error('panel_type_mismatch');
        }
        $token = $connection->credential('token');
        if ($token === null || $token === '') {
            return PanelResult::error('missing_credentials');
        }
        if (strlen($token) > 8192 || preg_match('/[\x00-\x1F\x7F]/', $token)) {
            return PanelResult::error('invalid_credentials');
        }
        try {
            $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
            $encoded = null;
            if ($body !== null) {
                $headers[] = 'Content-Type: application/json';
                $encoded = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
            $response = $this->transport->send($method, rtrim($connection->baseUrl, '/') . $path, $headers, $encoded);
            if ($response->status < 200 || $response->status >= 300) {
                return PanelResult::error(self::httpError($response->status));
            }
            return $response;
        } catch (Throwable) {
            return PanelResult::error('transport_error');
        }
    }

    /** @return array<string,mixed>|null - requires explicit success=true */
    private static function envelope(string $body): ?array
    {
        try {
            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            return is_array($data) && !array_is_list($data) && ($data['success'] ?? null) === true
                ? $data : null;
        } catch (JsonException) {
            return null;
        }
    }

    private static function httpError(int $status): string
    {
        return match ($status) {
            401 => 'authentication_failed', 403 => 'permission_denied',
            404 => 'account_not_found', 409 => 'account_already_exists',
            400, 422 => 'invalid_upstream_request',
            408, 429, 502, 503, 504 => 'upstream_unavailable',
            default => $status >= 500 ? 'upstream_error' : 'unexpected_upstream_status',
        };
    }
}
