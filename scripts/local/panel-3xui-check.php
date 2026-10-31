<?php

declare(strict_types=1);

/** Stage19 mock tests: all HTTP interactions stay in memory. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$project = dirname(__DIR__, 2);
$autoload = $project . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($project): void {
        $prefix = 'NRPro\\Pasargad\\';
        if (str_starts_with($class, $prefix)) {
            $path = $project . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($path)) {
                require $path;
            }
        }
    });
}

use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\Drivers\ThreeXuiDriver;
use NRPro\Pasargad\Modules\Vpn\Http\HttpResponse;
use NRPro\Pasargad\Modules\Vpn\Http\HttpTransportInterface;
use NRPro\Pasargad\Modules\Vpn\PanelDrivers;
use NRPro\Pasargad\Modules\Vpn\PanelManager;

final class Stage19MockTransport implements HttpTransportInterface
{
    public array $calls = [];
    private array $responses = [];

    public function reply(int $status, array|string $data): void
    {
        $this->responses[] = new HttpResponse($status, is_array($data)
            ? json_encode($data, JSON_THROW_ON_ERROR) : $data);
    }

    public function fail(): void
    {
        $this->responses[] = new RuntimeException('LEAK_TOKEN_MARKER');
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        if ($this->responses === []) {
            throw new RuntimeException('Unexpected request without an offline response');
        }
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        return $response;
    }
}

function ensure19(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
}

function sample19(string $email = 'alice@example.test'): array
{
    return ['success' => true, 'obj' => [
        'client' => [
            'email' => $email, 'uuid' => '94da6483-cc09-4701-8076-b3664a1b6c56',
            'subId' => 'secret-sub-id', 'totalGB' => 10737418240,
            'expiryTime' => 1800000000000, 'enable' => true, 'limitIp' => 2,
            'comment' => 'sample', 'password' => 'hidden-secret',
        ],
        'inboundIds' => [2, 5], 'usedTraffic' => 1234,
    ]];
}

try {
    $mock = new Stage19MockTransport();
    $registry = PanelDrivers::standard($mock);
    ensure19($registry->types() === ['marzban', 'xui3'], 'MARZBAN AND 3X-UI DRIVERS REGISTERED');
    ensure19(count((new ThreeXuiDriver($mock))->capabilities()) === 5, '3X-UI FIVE CAPABILITIES');
    $manager = new PanelManager($registry);
    $one = new PanelConnection('x-1', 'xui3', 'https://panel-one.example.test/prefix', ['token' => 'TOKEN_ONE']);
    $two = new PanelConnection('x-2', 'xui3', 'https://panel-two.example.test', ['token' => 'TOKEN_TWO']);

    $mock->reply(200, ['success' => true, 'obj' => ['items' => [], 'total' => 0]]);
    $ping = $manager->ping($one);
    ensure19($ping->success && $ping->data['panel'] === 'x-1', 'AUTHENTICATED PING');
    ensure19($mock->calls[0]['url'] === 'https://panel-one.example.test/prefix/panel/api/clients/list/paged?pageSize=1'
        && in_array('Authorization: Bearer TOKEN_ONE', $mock->calls[0]['headers'], true), 'PREFIX AND BEARER HEADER');

    $mock->reply(200, sample19());
    $read = $manager->getAccount($one, 'alice@example.test');
    ensure19($read->success && $read->data['account']['data_limit_bytes'] === 10737418240
        && $read->data['account']['used_traffic_bytes'] === 1234
        && $read->data['account']['expire_at'] === 1800000000000, 'READ + NORMALIZED BYTE/MILLISECOND UNITS');
    ensure19(!str_contains(json_encode($read->data), 'hidden-secret')
        && !str_contains(json_encode($read->data), 'secret-sub-id'), 'CLIENT SECRETS NOT LEAKED IN RESULT');
    ensure19(str_ends_with($mock->calls[1]['url'], '/clients/get/alice%40example.test'), 'SAFE ACCOUNT URL ENCODING');

    $mock->reply(200, ['success' => true, 'msg' => 'Client added']);
    $created = $manager->createAccount($one, [
        'email' => 'bob@example.test', 'inbound_ids' => [2, 5],
        'total_bytes' => 53687091200, 'expire_ms' => 1800000000000,
        'enable' => true, 'limit_ip' => 1,
    ]);
    $createCall = $mock->calls[2];
    $createBody = json_decode($createCall['body'], true, 512, JSON_THROW_ON_ERROR);
    ensure19($created->success && $createCall['method'] === 'POST'
        && str_ends_with($createCall['url'], '/clients/add')
        && $createBody['client']['totalGB'] === 53687091200
        && $createBody['inboundIds'] === [2, 5]
        && $createBody['client']['email'] === 'bob@example.test', 'MULTI-INBOUND CREATE AND DATA MAPPING');

    $mock->reply(200, sample19());
    $mock->reply(200, ['success' => true, 'msg' => 'Client updated']);
    $updated = $manager->updateAccount($one, 'alice@example.test', ['total_bytes' => 20_000_000_000, 'enable' => false]);
    $updateCall = $mock->calls[4];
    $updateBody = json_decode($updateCall['body'], true, 512, JSON_THROW_ON_ERROR);
    ensure19($updated->success && $updated->data['updated'] === true
        && $updateCall['method'] === 'POST'
        && $updateBody['totalGB'] === 20_000_000_000
        && $updateBody['enable'] === false
        && $updateBody['uuid'] === '94da6483-cc09-4701-8076-b3664a1b6c56'
        && $updateBody['password'] === 'hidden-secret'
        && !array_key_exists('inboundIds', $updateBody), 'UPDATE PRESERVES PROTOCOL SETTINGS / NO COUNTER REWRITE');

    $mock->reply(200, ['success' => true]);
    $deleted = $manager->deleteAccount($one, 'alice@example.test');
    ensure19($deleted->success && $deleted->data['deleted'] === true
        && $mock->calls[5]['method'] === 'POST'
        && str_ends_with($mock->calls[5]['url'], '/clients/del/alice%40example.test?keepTraffic=1'),
        'DELETE CLIENT ONLY, PRESERVE ACCOUNTING');

    $mock->reply(200, ['success' => true, 'obj' => ['items' => []]]);
    ensure19($manager->ping($two)->success
        && in_array('Authorization: Bearer TOKEN_TWO', $mock->calls[6]['headers'], true)
        && !in_array('Authorization: Bearer TOKEN_ONE', $mock->calls[6]['headers'], true), 'CREDENTIALS ISOLATED PER PANEL');

    $before = count($mock->calls);
    ensure19($manager->getAccount($one, '../escape')->code === 'invalid_account_id'
        && count($mock->calls) === $before, 'BLOCK ACCOUNT PATH TRAVERSAL');
    ensure19($manager->createAccount($one, ['email' => 'bob', 'inbound_ids' => [1, 1]])->code === 'invalid_specification'
        && count($mock->calls) === $before, 'BLOCK DUPLICATE INBOUND IDS');
    ensure19($manager->createAccount($one, ['email' => 'bob', 'inbound_ids' => [1], 'token' => 'stolen'])->code === 'invalid_specification'
        && count($mock->calls) === $before, 'REJECT UNEXPECTED CREATE FIELDS');
    ensure19($manager->createAccount($one, ['email' => 'bob', 'inbound_ids' => [1], 'expire_ms' => -1])->code === 'invalid_specification'
        && count($mock->calls) === $before, 'REJECT NEGATIVE EXPIRY');
    ensure19($manager->updateAccount($one, 'alice', ['email' => 'not-allowed'])->code === 'invalid_changes'
        && count($mock->calls) === $before, 'REJECT EMAIL MUTATION BEFORE REQUEST');
    ensure19($manager->deleteAccount($one, 'bad/slash')->code === 'invalid_account_id'
        && count($mock->calls) === $before, 'REJECT UNSAFE DELETE IDENTIFIER');

    $noCreds = new PanelConnection('nc', 'xui3', 'https://no-creds.example.test');
    ensure19($manager->ping($noCreds)->code === 'missing_credentials'
        && count($mock->calls) === $before, 'MISSING TOKEN BLOCKED OFFLINE');
    $badCreds = new PanelConnection('bc', 'xui3', 'https://no-creds.example.test', ['token' => "A\r\nX-Pwned: true"]);
    ensure19($manager->ping($badCreds)->code === 'invalid_credentials'
        && count($mock->calls) === $before, 'HEADER INJECTION BLOCKED');

    $mock->reply(401, '{"success":false,"msg":"token secret leak"}');
    ensure19($manager->ping($one)->code === 'authentication_failed', 'HTTP 401 MAPPED SAFELY');
    $mock->reply(302, '<html>redirect</html>');
    ensure19($manager->ping($one)->code === 'unexpected_upstream_status', 'NO REDIRECT AS SUCCESS');
    $mock->reply(200, '{"success":false,"msg":"sensitive upstream"}');
    ensure19($manager->ping($one)->code === 'invalid_upstream_response', 'UPSTREAM FAILURE DENIED');
    $mock->reply(200, '<html>not json</html>');
    ensure19($manager->ping($one)->code === 'invalid_upstream_response', 'INVALID JSON DENIED');
    $mock->reply(200, sample19('mallory@example.test'));
    ensure19($manager->getAccount($one, 'alice@example.test')->code === 'invalid_upstream_response', 'MISMATCHED ACCOUNT DENIED');
    $mock->fail();
    $error = $manager->ping($one);
    ensure19($error->code === 'transport_error'
        && !str_contains(json_encode($error->data), 'LEAK_TOKEN_MARKER'), 'TRANSPORT EXCEPTION REDACTED');

    echo '[SUCCESS] STAGE 19 3X-UI CLIENT API OFFLINE TESTS PASSED; NO NETWORK CALLS' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[FAILED] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
