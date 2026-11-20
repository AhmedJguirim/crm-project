<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

const CSP_ENFORCED = "frame-ancestors 'none'; base-uri 'self'; object-src 'none'";

function cspReportOnlyNonce(string $header): string
{
    expect($header)->toMatch("/^script-src 'self' 'nonce-([A-Za-z0-9]{40})' 'unsafe-eval'( \S+)?; report-uri \/csp-report\z/");
    preg_match("/'nonce-([A-Za-z0-9]{40})'/", $header, $matches);

    return $matches[1];
}

it('sends the report-only policy on every kind of response and leaves the enforced CSP unchanged', function (Closure $request) {
    $this->withoutVite();
    $response = $request($this);

    $response->assertHeader('Content-Security-Policy', CSP_ENFORCED);
    expect($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toMatch("/^script-src 'self' 'nonce-[A-Za-z0-9]{40}' 'unsafe-eval'; report-uri \/csp-report\z/");
})->with([
    'landing page' => [fn ($t) => $t->get('/')->assertOk()],
    'panel login' => [fn ($t) => $t->get(Filament::getLoginUrl())->assertOk()],
    'not found page' => [fn ($t) => $t->get('/no-such-page')->assertNotFound()],
    'livewire update endpoint' => [fn ($t) => $t->postJson(route('default-livewire.update'), ['components' => []], ['X-Livewire' => 'true'])],
]);

it('puts the header nonce on the scripts of the panel login and the landing page', function () {
    $this->withoutVite();

    $login = $this->get(Filament::getLoginUrl())->assertOk();
    $nonce = cspReportOnlyNonce($login->headers->get('Content-Security-Policy-Report-Only'));

    expect($login->getContent())->toMatch('/<script[^>]*src="[^"]*livewire[^"]*"[^>]*nonce="'.$nonce.'"|<script[^>]*nonce="'.$nonce.'"[^>]*src="[^"]*livewire/');

    $landing = $this->get('/')->assertOk();
    $landingNonce = cspReportOnlyNonce($landing->headers->get('Content-Security-Policy-Report-Only'));

    $landing->assertSee('<script nonce="'.$landingNonce.'">(() => { let theme = null;', false);
    expect($landingNonce)->not->toBe($nonce);
});

it('never reuses a nonce between two requests', function () {
    $this->withoutVite();

    $first = cspReportOnlyNonce($this->get('/')->headers->get('Content-Security-Policy-Report-Only'));
    $second = cspReportOnlyNonce($this->get('/')->headers->get('Content-Security-Policy-Report-Only'));

    expect($first)->not->toBe($second);
});

it('puts the nonce on the Horizon dashboard scripts', function () {
    app()->detectEnvironment(fn (): string => 'production');
    $ops = User::factory()->create(['email' => 'ops@example.com']);
    config(['horizon.allowed_user_ids' => [$ops->id]]);

    $response = $this->actingAs($ops)->get('/horizon')->assertOk();
    $nonce = cspReportOnlyNonce($response->headers->get('Content-Security-Policy-Report-Only'));

    $response->assertSee('<script type="module" nonce="'.$nonce.'">', false);
});

it('allows the Vite dev origin only while the dev server runs and only for a plain http(s) origin', function (string $hotFile, string $expected) {
    $path = tempnam(sys_get_temp_dir(), 'hot');
    file_put_contents($path, $hotFile);
    Vite::useHotFile($path);
    Route::get('/csp-probe', fn () => response('ok'));

    try {
        $header = $this->get('/csp-probe')->assertOk()->headers->get('Content-Security-Policy-Report-Only');
    } finally {
        unlink($path);
    }

    expect($header)->toMatch('/^script-src \'self\' \'nonce-[A-Za-z0-9]{40}\' \'unsafe-eval\''.$expected.'; report-uri \/csp-report\z/');
})->with([
    'ipv6 dev server' => ["http://[::1]:5173\n", ' http:\/\/\[::1\]:5173'],
    'path and query are dropped' => ["https://localhost:5173/some/path?x=1\n", ' https:\/\/localhost:5173'],
    'not a url' => ['not a url', ''],
    'a directive smuggled into the host' => ["http://evil.test;script-src-elem *:5173\n", ''],
    'a non-http scheme' => ["javascript://localhost:5173\n", ''],
    'empty file' => ['', ''],
]);

it('adds no origin when there is no hot file', function () {
    Vite::useHotFile(sys_get_temp_dir().'/no-such-hot-file-'.uniqid());
    Route::get('/csp-probe', fn () => response('ok'));

    expect($this->get('/csp-probe')->headers->get('Content-Security-Policy-Report-Only'))
        ->toMatch("/^script-src 'self' 'nonce-[A-Za-z0-9]{40}' 'unsafe-eval'; report-uri \/csp-report\z/");
});

it('keeps a report-only header the response already sets', function () {
    Route::get('/csp-probe', fn () => response('ok')->header('Content-Security-Policy-Report-Only', "default-src 'none'"));

    $this->get('/csp-probe')->assertHeader('Content-Security-Policy-Report-Only', "default-src 'none'");
});
