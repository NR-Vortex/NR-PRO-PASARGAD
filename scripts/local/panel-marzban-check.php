<?php

declare(strict_types=1);

/** Offline integration tests. No network and no real panel credentials required. */
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
use NRPro\Pasargad\Modules\Vpn\Drivers\MarzbanDriver;
use NRPro\Pasargad\Modules\Vpn\Http\HttpResponse;
use NRPro\Pasargad\Modules\Vpn\Http\HttpTransportInterface;
use NRPro\Pasargad\Modules\Vpn\PanelDrivers;
use NRPro\Pasargad\Modules\Vpn\PanelManager;

final class FakePanelTransport implements HttpTransportInterface
{
    /** @var list<array{string,string,array,?string}> */
    public array $calls = [];
    /** @var list<HttpResponse|\RuntimeException> */
    private array $responses = [];

    public function enqueue(int $status, string $body = ''): void
    {
        $this->responses[] = new HttpResponse($status, $body);
    }

    public function fail(): void
    {
        $this->responses[] = new RuntimeException('Secret URL or token must never leak');
    }

    public function send(string $method, string $url, array $headers = [], ?string $body = null): HttpResponse
    {
        $this->calls[] = [$method, $url, $headers, $body];
        if ($this->responses === []) {
            throw new LogicException('Unexpected outgoing request in offline test.');
        }
        $response = array_shift($this->responses);
        if ($response instanceof RuntimeException) {
            throw $response;
        }
        return $response;
    }
}

function assertPanel(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $description);
    }
    echo '[OK] ' . $description . PHP_EOL;
}

function sampleUser(string $username = 'alice_01'): string
{
    return json_encode([
        'username' => $username,
        'status' => 'active',
        'data_limit' => 10_737_418_240,
        'used_traffic' => 123456,
        'expire' => 1790000000,
        'links' => ['vless://TEST-ONLY'],
        'subscription_url' => 'https://sub.example.test/SAMPLE-ONLY',
    ], JSON_THROW_ON_ERROR);
}

try {
    $mock = new FakePanelTransport();
    $registry = PanelDrivers::standard($mock);
    $manager = new PanelManager($registry);
    $a = new PanelConnection('panel-a', 'marzban', 'https://panel-a.example.test', ['token' => 'TOKEN_A']);
    $b = new PanelConnection('panel-b', 'marzban', 'https://panel-b.example.test', ['username' => 'admin', 'password' => 'PASS_B']);
    assertPanel(in_array('marzban', $registry->types(), true), 'REAL MARZBAN DRIVER REGISTERED');
    assertPanel(count((new MarzbanDriver($mock))->capabilities()) === 5, 'ALL FIVE PANEL CAPABILITIES AVAILABLE');

    $mock->enqueue(200, '{"username":"admin","is_sudo":true}');
    $ok = $manager->ping($a);
    assertPanel($ok->success && $ok->data['panel'] === 'panel-a', 'TOKEN AUTHENTICATION / PING');
    assertPanel($mock->calls[0][0] === 'GET' && $mock->calls[0][1] === 'https://panel-a.example.test/api/admin'
        && in_array('Authorization: Bearer TOKEN_A', $mock->calls[0][2], true), 'RIGHT PANEL URL / BEARER HEADER');

    $mock->enqueue(200, sampleUser());
    $user = $manager->getAccount($a, 'alice_01');
    assertPanel($user->success && $user->data['account']['data_limit_bytes'] === 10_737_418_240
        && $user->data['account']['subscription_url'] === 'https://sub.example.test/SAMPLE-ONLY', 'READ AND NORMALIZE USER');
    assertPanel($mock->calls[1][1] === 'https://panel-a.example.test/api/user/alice_01', 'READ USES ONLY USER PATH');

    $mock->enqueue(200, sampleUser());
    $created = $manager->createAccount($a, [
        'username' => 'alice_01', 'proxies' => ['vless' => []], 'inbounds' => ['vless' => ['REAlITY']],
        'data_limit' => 10_737_418_240, 'expire' => 1790000000,
    ]);
    assertPanel($created->success && $mock->calls[2][0] === 'POST', 'CREATE USER VIA POST');
    $posted = json_decode((string) $mock->calls[2][3], true, 512, JSON_THROW_ON_ERROR);
    assertPanel($posted['username'] === 'alice_01' && str_contains((string) $mock->calls[2][3], '"vless":{}'), 'JSON BODY USES MARZBAN PROXY OBJECT');

    $mock->enqueue(200, sampleUser());
    $updated = $manager->updateAccount($a, 'alice_01', ['data_limit' => 20_000_000_000]);
    assertPanel($updated->success && $mock->calls[3][0] === 'PUT', 'UPDATE USER VIA PUT');

    $mock->enqueue(200, '{"detail":"User removed successfully"}');
    $deleted = $manager->deleteAccount($a, 'alice_01');
    assertPanel($deleted->success && $deleted->data['deleted'] === true && $mock->calls[4][0] === 'DELETE', 'DELETE USER VIA DELETE');

    $mock->enqueue(200, '{"access_token":"JWT_FROM_MOCK"}');
    $mock->enqueue(200, '{"username":"admin"}');
    assertPanel($manager->ping($b)->success, 'USERNAME/PASSWORD OAUTH AUTHENTICATION');
    $authCall = $mock->calls[5];
    assertPanel($authCall[0] === 'POST' && str_ends_with($authCall[1], '/api/admin/token')
        && str_contains((string) $authCall[3], 'password=PASS_B'), 'OAUTH FORM POST (NO LOGGING)');
    assertPanel($mock->calls[6][1] === 'https://panel-b.example.test/api/admin'
        && in_array('Authorization: Bearer JWT_FROM_MOCK', $mock->calls[6][2], true), 'PANEL B AUTH SEPARATE FROM PANEL A');

    $mock->enqueue(404, '{"detail":"Account not found: SECRET"}');
    assertPanel($manager->getAccount($a, 'missing')->code === 'account_not_found', '404 MAPPED WITHOUT UPSTREAM ERROR BODY');
    $before = count($mock->calls);
    assertPanel($manager->getAccount($a, '../escape')->code === 'invalid_account_id'
        && count($mock->calls) === $before, 'INVALID ACCOUNT ID BLOCKED WITHOUT NETWORK');
    assertPanel($manager->createAccount($a, ['username'=>'alice_01', 'proxies'=>['vless'=>[]], 'unexpected'=>'evil'])->code === 'invalid_specification'
        && count($mock->calls) === $before, 'UNEXPECTED CREATE FIELDS BLOCKED');
    assertPanel($manager->createAccount($a, ['username'=>'alice_01'])->code === 'invalid_specification'
        && count($mock->calls) === $before, 'MISSING PROXIES BLOCKED');
    assertPanel($manager->updateAccount($a, 'alice_01', ['username'=>'renamed'])->code === 'invalid_changes'
        && count($mock->calls) === $before, 'USERNAME CANNOT BE MUTATED');

    $noCreds = new PanelConnection('no-creds', 'marzban', 'https://panel-c.example.test');
    assertPanel($manager->ping($noCreds)->code === 'missing_credentials'
        && count($mock->calls) === $before, 'MISSING CREDENTIALS BLOCKED WITHOUT NETWORK');
    $crlf = new PanelConnection('bad-token', 'marzban', 'https://panel-c.example.test', ['token' => "abc\r\nHijack: yes"]);
    assertPanel($manager->ping($crlf)->code === 'invalid_credentials'
        && count($mock->calls) === $before, 'HTTP HEADER INJECTION BLOCKED');

    $mock->enqueue(302, 'redirect');
    assertPanel($manager->ping($a)->code === 'unexpected_upstream_status', 'HTTP REDIRECT NEVER TREATED AS SUCCESS');
    $mock->enqueue(200, '<html>Bad gateway</html>');
    assertPanel($manager->ping($a)->code === 'invalid_upstream_response', 'NON-JSON PING NEVER TREATED AS SUCCESS');
    $mock->fail();
    $failed = $manager->ping($a);
    assertPanel($failed->code === 'transport_error' && $failed->data === [], 'EXCEPTIONS REDACTED');

    echo '[SUCCESS] STAGE 18 MARZBAN LIVE DRIVER OFFLINE TESTS PASSED; NO NETWORK CALLS' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '[FAILED] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
