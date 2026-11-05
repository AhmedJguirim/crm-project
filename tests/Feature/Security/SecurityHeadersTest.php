<?php

use App\Filament\Pages\Dashboard;
use App\Jobs\ProcessContactImportJob;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

function securityHeadersExpected(): array
{
    return [
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ];
}

function assertSecurityHeaders(TestResponse $response): void
{
    foreach (securityHeadersExpected() as $name => $value) {
        $response->assertHeader($name, $value);
    }
    $response->assertHeaderMissing('Strict-Transport-Security');
}

it('adds the five security headers to every kind of response', function (Closure $request) {
    $this->withoutVite();
    $response = $request($this);

    assertSecurityHeaders($response);
})->with([
    'landing page' => [fn ($t) => $t->get('/')->assertOk()],
    'panel login' => [fn ($t) => $t->get(Filament::getLoginUrl())->assertOk()],
    'fortify login' => [fn ($t) => $t->get(route('login'))->assertOk()],
    'not found page' => [fn ($t) => $t->get('/no-such-page')->assertNotFound()],
    'livewire update endpoint' => [fn ($t) => $t->postJson(route('default-livewire.update'), ['components' => []], ['X-Livewire' => 'true'])],
]);

it('adds the headers to the dashboard of a logged-in owner', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $response = $this->actingAs($user)->get(Dashboard::getUrl(tenant: $user->personalOrganization()))->assertOk();

    assertSecurityHeaders($response);
});

it('adds the headers to a file download', function () {
    Storage::fake('local');
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    Storage::disk('local')->put('contact-imports/ann.csv', "name,email,phone,tags\nBroken,not-an-email,,\n");
    ProcessContactImportJob::dispatchSync('contact-imports/ann.csv', $user->personalOrganization()->id, $user->id);
    $url = DatabaseNotification::where('notifiable_id', $user->id)->sole()->data['actions'][0]['url'];

    $response = $this->actingAs($user)->get($url)->assertOk();

    expect($response->baseResponse->headers->get('content-disposition'))->toContain('attachment');
    assertSecurityHeaders($response);
});

it('sends HSTS in production on an https APP_URL', function () {
    $this->withoutVite();
    app()->detectEnvironment(fn (): string => 'production');
    config(['app.url' => 'https://crm.example.com']);

    $response = $this->get('/')->assertOk();

    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    expect($response->headers->get('Strict-Transport-Security'))->not->toContain('includeSubDomains')->not->toContain('preload');
});

it('does not send HSTS without production and an https APP_URL', function (string $environment, string $url) {
    $this->withoutVite();
    app()->detectEnvironment(fn (): string => $environment);
    config(['app.url' => $url]);

    $this->get('/')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
})->with([
    'production on http' => ['production', 'http://crm.example.com'],
    'testing on https' => ['testing', 'https://crm.example.com'],
]);

it('keeps a header the response already sets', function () {
    Route::get('/security-headers-probe', fn () => response('ok')->header('Content-Security-Policy', "default-src 'none'"));

    $response = $this->get('/security-headers-probe')->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'");
    $response->assertHeader('X-Frame-Options', 'DENY');
});
