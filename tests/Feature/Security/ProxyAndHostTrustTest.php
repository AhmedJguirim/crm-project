<?php

use App\Support\Security\ProxyTrust;
use Carbon\CarbonImmutable;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
});

afterEach(function () {
    $this->app['env'] = 'testing';
    SymfonyRequest::setTrustedHosts([]);
});

/**
 * @param  array<string, string>  $headers
 */
function proxyProbe(string $remoteAddr, array $headers = [], string $url = 'http://crm.example.com/test-proxy-view'): TestResponse
{
    Route::get('/test-proxy-view', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'host' => $request->getHost(),
        'scheme' => $request->getScheme(),
    ]));

    return test()->withServerVariables(['REMOTE_ADDR' => $remoteAddr])->withHeaders($headers)->get($url);
}

/**
 * @param  array<string, string>  $headers
 */
function proxyResetPost(string $email, string $remoteAddr, array $headers = []): TestResponse
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->withHeaders($headers)
        ->from(route('password.request'))
        ->post(route('password.email'), ['email' => $email]);
}

test('the TRUSTED_PROXIES value becomes the proxy list', function (?string $value, array $list) {
    expect(ProxyTrust::proxies($value))->toBe($list);
})->with([
    'unset' => [null, ['127.0.0.1', '::1']],
    'empty' => ['', []],
    'only separators' => ['  ,  ', []],
    'one address' => ['10.0.0.5', ['10.0.0.5']],
    'spaces and a trailing comma' => [' 10.0.0.0/8 , 192.168.1.10 ,', ['10.0.0.0/8', '192.168.1.10']],
    'ipv4, ipv6 and a range' => ['127.0.0.1,::1,2001:db8::/32', ['127.0.0.1', '::1', '2001:db8::/32']],
    'star' => ['*', []],
    'double star next to an address' => ['**, 10.0.0.5', ['10.0.0.5']],
    'remote_addr any case' => ['remote_addr', []],
    'malformed items pass through' => ['not-an-ip,10.0.0.0/99', ['not-an-ip', '10.0.0.0/99']],
]);

test('the test suite uses the default proxy list', function () {
    expect(config('trustedproxy.proxies'))->toBe(['127.0.0.1', '::1']);
});

test('nginx on the same host passes the real client address and scheme', function (string $proxy, string $client) {
    proxyProbe($proxy, ['X-Forwarded-For' => $client, 'X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertExactJson(['ip' => $client, 'host' => 'crm.example.com', 'scheme' => 'https']);
})->with([
    'ipv4 loopback' => ['127.0.0.1', '198.51.100.23'],
    'ipv6 loopback' => ['::1', '2001:db8::5'],
]);

test('a stranger calling the server directly cannot fake their address or scheme', function () {
    proxyProbe('203.0.113.9', ['X-Forwarded-For' => '198.51.100.23', 'X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertExactJson(['ip' => '203.0.113.9', 'host' => 'crm.example.com', 'scheme' => 'http']);
});

test('an address a client forged in front of the proxy chain is not used', function () {
    proxyProbe('127.0.0.1', ['X-Forwarded-For' => '6.6.6.6, 198.51.100.23'])
        ->assertOk()
        ->assertJsonPath('ip', '198.51.100.23');
});

test('garbage in X-Forwarded-For falls back to the caller without a 500', function () {
    proxyProbe('127.0.0.1', ['X-Forwarded-For' => 'garbage, ;drop'])
        ->assertStatus(200)
        ->assertJsonPath('ip', '127.0.0.1');
});

test('X-Forwarded-Host and Forwarded are never trusted, even from the proxy', function () {
    proxyProbe('127.0.0.1', ['X-Forwarded-Host' => 'evil.example', 'Forwarded' => 'for=198.51.100.99;host=evil.example;proto=https'])
        ->assertOk()
        ->assertExactJson(['ip' => '127.0.0.1', 'host' => 'crm.example.com', 'scheme' => 'http']);
});

test('a star list and an empty list trust nobody', function (?string $value) {
    config(['trustedproxy.proxies' => ProxyTrust::proxies($value)]);

    proxyProbe('127.0.0.1', ['X-Forwarded-For' => '198.51.100.23'])
        ->assertOk()
        ->assertJsonPath('ip', '127.0.0.1');
})->with(['star' => '*', 'empty' => '']);

test('a configured range replaces the loopback default', function () {
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

    proxyProbe('10.1.2.3', ['X-Forwarded-For' => '198.51.100.23'])->assertJsonPath('ip', '198.51.100.23');
    proxyProbe('127.0.0.1', ['X-Forwarded-For' => '198.51.100.23'])->assertJsonPath('ip', '127.0.0.1');
});

describe('signed links behind TLS termination', function () {
    beforeEach(function () {
        Route::get('/test-signed', fn () => 'ok')->middleware('signed')->name('test.signed');
        Route::getRoutes()->refreshNameLookups();
        URL::forceRootUrl('https://crm.example.com');
        URL::forceScheme('https');
        $signedUrl = URL::signedRoute('test.signed');
        expect($signedUrl)->toStartWith('https://crm.example.com/test-signed?signature=');
        $this->plainUrl = str_replace('https://', 'http://', $signedUrl);
    });

    test('through the proxy with X-Forwarded-Proto https', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get($this->plainUrl)
            ->assertOk()
            ->assertSee('ok');
    });

    test('the same header from a direct caller is ignored', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->get($this->plainUrl)
            ->assertStatus(403);
    });

    test('through the proxy without the header', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get($this->plainUrl)
            ->assertStatus(403);
    });
});

describe('rate limits and the security log see the real client', function () {
    beforeEach(function () {
        Notification::fake();
    });

    test('a throttled password reset behind the proxy logs the visitor', function () {
        $log = fakeSecurityLog();

        foreach (range(1, 5) as $i) {
            proxyResetPost('someone@example.com', '127.0.0.1', ['X-Forwarded-For' => '198.51.100.23'])
                ->assertRedirect(route('password.request'));
        }

        proxyResetPost('someone@example.com', '127.0.0.1', ['X-Forwarded-For' => '198.51.100.23'])->assertStatus(429);

        $lockouts = array_values(array_filter(securityLogEntries($log), fn (array $entry): bool => $entry['event'] === 'auth.lockout'));

        expect($lockouts)->toHaveCount(1)
            ->and($lockouts[0]['context']['ip'])->toBe('198.51.100.23');
    });

    test('two visitors behind the proxy do not share a bucket', function () {
        foreach (range(1, 5) as $i) {
            proxyResetPost('someone@example.com', '127.0.0.1', ['X-Forwarded-For' => '198.51.100.23']);
        }

        proxyResetPost('someone@example.com', '127.0.0.1', ['X-Forwarded-For' => '198.51.100.23'])->assertStatus(429);

        proxyResetPost('other@example.com', '127.0.0.1', ['X-Forwarded-For' => '198.51.100.24'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasNoErrors();
    });

    test('a stranger rotating X-Forwarded-For gets no fresh bucket', function () {
        foreach (range(1, 5) as $i) {
            proxyResetPost('someone@example.com', '203.0.113.9', ['X-Forwarded-For' => "198.51.100.{$i}"])
                ->assertRedirect(route('password.request'));
        }

        proxyResetPost('someone@example.com', '203.0.113.9', ['X-Forwarded-For' => '198.51.100.6'])->assertStatus(429);
    });
});

test('host patterns come from the APP_URL host only', function (?string $url, array $patterns) {
    expect(ProxyTrust::hostPatterns($url))->toBe($patterns);
})->with([
    'plain https' => ['https://crm.example.com', ['^crm\.example\.com$']],
    'user info, port and path' => ['https://user@crm.example.com:8443/a', ['^crm\.example\.com$']],
    'empty' => ['', []],
    'null' => [null, []],
    'no host' => ['not a url', []],
]);

test('the registered middleware trusts exactly the APP_URL host', function () {
    config(['app.url' => 'https://crm.example.com']);

    expect(app(TrustHosts::class)->hosts())->toBe(['^crm\.example\.com$']);
});

test('in production only the APP_URL host is served', function (string $url, array $server, array $headers, int $status) {
    config(['app.url' => 'https://crm.example.com']);
    $this->app['env'] = 'production';

    $this->withServerVariables($server)->withHeaders($headers)->get($url)->assertStatus($status);
})->with([
    'the host' => ['https://crm.example.com/up', [], [], 200],
    'the host in capitals' => ['https://CRM.example.com/up', [], [], 200],
    'another host' => ['http://evil.example/up', [], [], 400],
    'a subdomain' => ['https://www.crm.example.com/up', [], [], 400],
    'a look-alike host with another character for the dot' => ['https://crmxexample.com/up', [], [], 400],
    'the host as a prefix of another host' => ['https://crm.example.com.evil.example/up', [], [], 400],
    'another host with a forwarded host from the proxy' => ['http://evil.example/up', ['REMOTE_ADDR' => '127.0.0.1'], ['X-Forwarded-Host' => 'crm.example.com'], 400],
]);

test('local and testing skip the host check', function (string $environment) {
    config(['app.url' => 'https://crm.example.com']);
    $this->app['env'] = $environment;

    $this->get('http://evil.example/up')->assertStatus(200);
})->with(['local', 'testing']);

test('the trusted host list does not leak into the next test', function () {
    expect(SymfonyRequest::getTrustedHosts())->toBe([]);

    $this->get('http://evil.example/up')->assertStatus(200);
});
