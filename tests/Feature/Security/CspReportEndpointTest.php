<?php

use App\Support\Security\SecurityLog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

function postCspReport(mixed $payload, string $contentType = 'application/csp-report'): TestResponse
{
    $body = is_string($payload) ? $payload : json_encode($payload);

    return test()->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => $contentType], $body);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{csp-report: array<string, mixed>}
 */
function cspReportBody(array $overrides = []): array
{
    return ['csp-report' => array_merge([
        'document-uri' => 'http://localhost/invite/accept/secret-token-123?x=1',
        'violated-directive' => 'script-src-elem',
        'effective-directive' => 'script-src-elem',
        'blocked-uri' => 'https://evil.example/x.js?token=abc',
        'source-file' => 'http://localhost/admin/login',
        'line-number' => 42,
        'script-sample' => 'alert(document.cookie)',
        'original-policy' => "script-src 'self'",
    ], $overrides)];
}

it('logs a browser report without URLs, tokens or samples', function () {
    $log = fakeSecurityLog();

    $response = postCspReport(cspReportBody())->assertNoContent();

    expect($response->headers->getCookies())->toBe([]);
    $entries = securityLogEntries($log);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('csp.violation')
        ->and($entries[0]['level'])->toBe('info')
        ->and($entries[0]['context'])->toMatchArray([
            'directive' => 'script-src-elem',
            'blocked' => 'https://evil.example',
            'source' => 'http://localhost',
            'line' => 42,
            'page_route' => 'invite.accept',
            'user_id' => null,
            'organization_id' => null,
            'ip' => '127.0.0.1',
        ]);

    $logged = json_encode($entries[0]['context']);
    foreach (['secret-token-123', 'token=abc', 'alert(', "script-src 'self'", '?x=1'] as $forbidden) {
        expect($logged)->not->toContain($forbidden);
    }
    expect(array_keys($entries[0]['context']))->toEqualCanonicalizing(['directive', 'blocked', 'source', 'line', 'page_route', 'user_id', 'organization_id', 'ip']);
});

it('reduces the blocked uri', function (mixed $blocked, ?string $logged) {
    $log = fakeSecurityLog();

    postCspReport(cspReportBody(['blocked-uri' => $blocked]))->assertNoContent();

    expect(securityLogEntries($log)[0]['context']['blocked'])->toBe($logged);
})->with([
    'inline' => ['inline', 'inline'],
    'eval' => ['eval', 'eval'],
    'wasm-eval' => ['wasm-eval', 'wasm-eval'],
    'data' => ['data:text/javascript,alert(1)', 'data'],
    'blob' => ['blob:https://crm.example.com/uuid', 'blob'],
    'vite dev server' => ['http://[::1]:5173/@vite/client', 'http://[::1]:5173'],
    'extension' => ['chrome-extension://abcdef/content.js', 'chrome-extension://abcdef'],
    'not a url' => ['not a url', 'other'],
    'an int' => [123, null],
    'an array' => [['https://evil.example'], null],
    'empty string' => ['', null],
    'seriously malformed' => ['http:///x', 'other'],
]);

it('cuts a very long origin to 200 characters', function () {
    $log = fakeSecurityLog();

    postCspReport(cspReportBody(['blocked-uri' => 'https://'.str_repeat('a', 500).'.example/x']))->assertNoContent();

    expect(mb_strlen(securityLogEntries($log)[0]['context']['blocked']))->toBe(200);
});

it('neutralises a forged directive and an unknown page', function () {
    $log = fakeSecurityLog();

    postCspReport(cspReportBody(['effective-directive' => 'script-src<script>', 'document-uri' => 'http://localhost/no-such-page']))->assertNoContent();

    expect(securityLogEntries($log)[0]['context'])->toMatchArray(['directive' => 'other', 'page_route' => null]);
});

it('does not fall back to the violated directive when the effective one is forged', function () {
    $log = fakeSecurityLog();

    postCspReport(cspReportBody(['effective-directive' => str_repeat('a', 41), 'violated-directive' => 'script-src']))->assertNoContent();

    expect(securityLogEntries($log)[0]['context']['directive'])->toBe('other');
});

it('reads the first token of the violated directive when the browser sends no effective directive', function () {
    $log = fakeSecurityLog();
    $report = cspReportBody(['violated-directive' => "script-src-attr 'self'"]);
    unset($report['csp-report']['effective-directive']);

    postCspReport($report)->assertNoContent();

    expect(securityLogEntries($log)[0]['context']['directive'])->toBe('script-src-attr');
});

it('keeps only a plausible line number', function (mixed $line, ?int $logged) {
    $log = fakeSecurityLog();

    postCspReport(cspReportBody(['line-number' => $line]))->assertNoContent();

    expect(securityLogEntries($log)[0]['context']['line'])->toBe($logged);
})->with([
    'zero' => [0, 0],
    'max' => [10_000_000, 10_000_000],
    'over max' => [10_000_001, null],
    'negative' => [-1, null],
    'a string' => ['42', null],
    'a float' => [4.5, null],
    'an array' => [[42], null],
]);

it('logs the route name of the page, never its path', function () {
    $log = fakeSecurityLog();

    postCspReport(cspReportBody(['document-uri' => 'http://localhost/privacy?a=b#frag']))->assertNoContent();

    expect(securityLogEntries($log)[0]['context']['page_route'])->toBe('privacy');
});

it('ignores a document uri that is not a string', function () {
    $log = fakeSecurityLog();

    postCspReport(cspReportBody(['document-uri' => ['x']]))->assertNoContent();

    expect(securityLogEntries($log)[0]['context']['page_route'])->toBeNull();
});

it('refuses a malformed report without a log line or an error', function (string $body, int $status) {
    $log = fakeSecurityLog();

    postCspReport($body)->assertStatus($status);

    expect(securityLogEntries($log))->toBe([]);
})->with([
    'not json' => ['not json', 400],
    'empty body' => ['', 400],
    'another document' => ['{"something":"else"}', 400],
    'a string report' => ['{"csp-report":"a string"}', 400],
    'a json string' => ['"csp-report"', 400],
    'nested too deep' => [str_repeat('[', 20).str_repeat(']', 20), 400],
]);

it('refuses a body over 16384 bytes, and accepts one of exactly 16384', function () {
    $log = fakeSecurityLog();
    $report = cspReportBody();

    $pad = fn (int $size): string => json_encode($report + ['pad' => str_repeat('a', $size - strlen(json_encode($report + ['pad' => ''])))]);

    expect(strlen($pad(16385)))->toBe(16385)
        ->and(strlen($pad(16384)))->toBe(16384);

    postCspReport($pad(16385))->assertStatus(413);
    expect(securityLogEntries($log))->toBe([]);

    postCspReport($pad(16384))->assertNoContent();
    expect(securityLogEntries($log))->toHaveCount(1);
});

it('decodes the raw body whatever the content type says', function (string $contentType) {
    $log = fakeSecurityLog();

    postCspReport(cspReportBody(), $contentType)->assertNoContent();

    expect(securityLogEntries($log))->toHaveCount(1);
})->with(['application/csp-report', 'application/json', 'text/plain']);

it('caps a flooding guest silently at 30 a minute', function () {
    $log = fakeSecurityLog();

    foreach (range(1, 30) as $i) {
        postCspReport(cspReportBody())->assertNoContent();
    }
    postCspReport(cspReportBody())->assertStatus(429);

    $events = array_column(securityLogEntries($log), 'event');
    expect($events)->toHaveCount(30)
        ->and(array_unique($events))->toBe(['csp.violation']);
});

it('counts every IP on its own and caps all of them together', function () {
    $log = fakeSecurityLog();

    foreach (range(1, 30) as $i) {
        postCspReport(cspReportBody())->assertNoContent();
    }
    postCspReport(cspReportBody())->assertStatus(429);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode(cspReportBody()))->assertNoContent();

    expect(securityLogEntries($log))->toHaveCount(31);
});

it('stops every IP once the global hourly budget is spent', function () {
    $log = fakeSecurityLog();
    foreach (range(1, 2000) as $i) {
        RateLimiter::hit(md5('csp-reports'.'global'), 3600);
    }

    postCspReport(cspReportBody())->assertStatus(429);

    expect(securityLogEntries($log))->toBe([]);
});

it('stops one IP once its hourly budget is spent', function () {
    $log = fakeSecurityLog();
    foreach (range(1, 300) as $i) {
        RateLimiter::hit(md5('csp-reports'.'hour|127.0.0.1'), 3600);
    }

    postCspReport(cspReportBody())->assertStatus(429);

    expect(securityLogEntries($log))->toBe([]);
});

it('does not use up the refusal cap of real refusals', function () {
    $log = fakeSecurityLog();

    foreach (range(1, 30) as $i) {
        postCspReport(cspReportBody())->assertNoContent();
    }

    SecurityLog::refusal('request.forbidden');

    $events = array_column(securityLogEntries($log), 'event');
    expect($events)->toContain('request.forbidden')
        ->and($events)->not->toContain('request.refusals_capped');
});

it('creates no session and no cookie', function () {
    config(['session.driver' => 'database']);

    $response = postCspReport(cspReportBody())->assertNoContent();

    expect($response->headers->getCookies())->toBe([])
        ->and(DB::table('sessions')->count())->toBe(0);
});

it('carries no csrf, session or cookie middleware', function () {
    $excluded = app('router')->getRoutes()->getByName('csp.report')->excludedMiddleware();

    expect($excluded)->toContain(ValidateCsrfToken::class)
        ->and($excluded)->toContain(StartSession::class);
});

it('answers only POST and is a named route', function () {
    $this->get('/csp-report')->assertStatus(405);
    expect(route('csp.report', absolute: false))->toBe('/csp-report');
});
