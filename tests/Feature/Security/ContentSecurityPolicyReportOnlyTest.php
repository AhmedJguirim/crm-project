<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\User;
use Dom\HTMLDocument;
use Filament\Facades\Filament;
use Illuminate\Foundation\Vite as FoundationVite;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Tests\TestCase;

const CSP_BASE = "frame-ancestors 'none'; base-uri 'self'; object-src 'none'";

function cspNonce(string $header): string
{
    expect($header)->toMatch("/^frame-ancestors 'none'; base-uri 'self'; object-src 'none'; script-src 'self' 'nonce-([A-Za-z0-9]{40})' 'unsafe-eval'( \S+)?; worker-src 'self' blob:; report-uri \/csp-report\z/");
    preg_match("/'nonce-([A-Za-z0-9]{40})'/", $header, $matches);

    return $matches[1];
}

it('enforces the script policy on every kind of response and sends no report-only header', function (Closure $request) {
    $this->withoutVite();
    $response = $request($this);

    expect($response->headers->get('Content-Security-Policy'))
        ->toMatch("/^frame-ancestors 'none'; base-uri 'self'; object-src 'none'; script-src 'self' 'nonce-[A-Za-z0-9]{40}' 'unsafe-eval'; worker-src 'self' blob:; report-uri \/csp-report\z/");
    $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
})->with([
    'landing page' => [fn ($t) => $t->get('/')->assertOk()],
    'panel login' => [fn ($t) => $t->get(Filament::getLoginUrl())->assertOk()],
    'not found page' => [fn ($t) => $t->get('/no-such-page')->assertNotFound()],
    'livewire update endpoint' => [fn ($t) => $t->postJson(route('default-livewire.update'), ['components' => []], ['X-Livewire' => 'true'])],
    'health page' => [fn ($t) => $t->get('/up')->assertOk()],
]);

it('allows nothing broader than the nonce, self, eval and blob workers', function () {
    $this->withoutVite();

    $header = (string) $this->get('/')->assertOk()->headers->get('Content-Security-Policy');

    foreach (["'unsafe-inline'", "'strict-dynamic'", ' * ', ' https: ', ' data: ', 'cdn.jsdelivr.net'] as $forbidden) {
        expect($header)->not->toContain($forbidden);
    }

    expect(substr_count($header, 'blob:'))->toBe(1)
        ->and($header)->toContain("worker-src 'self' blob:;")
        ->and($header)->toContain(CSP_BASE.'; script-src');
});

it('enforces the policy on the health page without allowing its CDN script', function () {
    $this->withoutVite();

    $response = $this->get('/up')->assertOk();

    expect($response->getContent())->toContain('Application up')
        ->and($response->headers->get('Content-Security-Policy'))->not->toContain('jsdelivr');
});

it('sends only the base policy on the debug exception page', function () {
    config(['app.debug' => true]);
    Route::get('/csp-boom', fn () => throw new RuntimeException('csp boom'));

    $response = $this->get('/csp-boom')->assertStatus(500);

    expect($response->headers->get('Content-Security-Policy'))->toBe(CSP_BASE);
    $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
});

it('fully enforces the policy on a 500 page when debug is off', function () {
    config(['app.debug' => false]);
    Route::get('/csp-boom', fn () => throw new RuntimeException('csp boom'));

    $response = $this->get('/csp-boom')->assertStatus(500);

    cspNonce($response->headers->get('Content-Security-Policy'));
});

it('fully enforces the policy on a 404 page in debug mode', function () {
    config(['app.debug' => true]);

    $response = $this->get('/no-such-page')->assertNotFound();

    cspNonce($response->headers->get('Content-Security-Policy'));
});

it('fully enforces the policy on a debug response that is 500 without an exception', function () {
    config(['app.debug' => true]);
    Route::get('/csp-plain-500', fn () => response('plain', 500));

    $response = $this->get('/csp-plain-500')->assertStatus(500);

    cspNonce($response->headers->get('Content-Security-Policy'));
});

it('puts the header nonce on the scripts of the panel login and the landing page', function () {
    $this->withoutVite();

    $login = $this->get(Filament::getLoginUrl())->assertOk();
    $nonce = cspNonce($login->headers->get('Content-Security-Policy'));

    expect($login->getContent())->toMatch('/<script[^>]*src="[^"]*livewire[^"]*"[^>]*nonce="'.$nonce.'"|<script[^>]*nonce="'.$nonce.'"[^>]*src="[^"]*livewire/');

    $landing = $this->get('/')->assertOk();
    $landingNonce = cspNonce($landing->headers->get('Content-Security-Policy'));

    $landing->assertSee('<script nonce="'.$landingNonce.'">(() => { let theme = null;', false);
    expect($landingNonce)->not->toBe($nonce);
});

it('never reuses a nonce between two requests', function () {
    $this->withoutVite();

    $first = cspNonce($this->get('/')->headers->get('Content-Security-Policy'));
    $second = cspNonce($this->get('/')->headers->get('Content-Security-Policy'));

    expect($first)->not->toBe($second);
});

it('puts the nonce on the Horizon dashboard scripts', function () {
    app()->detectEnvironment(fn (): string => 'production');
    $ops = User::factory()->create(['email' => 'ops@example.com']);
    config(['horizon.allowed_user_ids' => [$ops->id]]);

    $response = $this->actingAs($ops)->get('/horizon')->assertOk();
    $nonce = cspNonce($response->headers->get('Content-Security-Policy'));

    $response->assertSee('<script type="module" nonce="'.$nonce.'">', false);
});

it('allows the Vite dev origin only while the dev server runs and only for a plain http(s) origin', function (string $hotFile, string $expected) {
    $path = tempnam(sys_get_temp_dir(), 'hot');
    file_put_contents($path, $hotFile);
    Vite::useHotFile($path);
    Route::get('/csp-probe', fn () => response('ok'));

    try {
        $header = $this->get('/csp-probe')->assertOk()->headers->get('Content-Security-Policy');
    } finally {
        unlink($path);
    }

    expect($header)->toMatch('/^frame-ancestors \'none\'; base-uri \'self\'; object-src \'none\'; script-src \'self\' \'nonce-[A-Za-z0-9]{40}\' \'unsafe-eval\''.$expected.'; worker-src \'self\' blob:; report-uri \/csp-report\z/');
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

    expect($this->get('/csp-probe')->headers->get('Content-Security-Policy'))
        ->toMatch("/^frame-ancestors 'none'; base-uri 'self'; object-src 'none'; script-src 'self' 'nonce-[A-Za-z0-9]{40}' 'unsafe-eval'; worker-src 'self' blob:; report-uri \/csp-report\z/");
});

it('ignores a running Vite dev server once Vite is disabled for the test', function () {
    $directory = sys_get_temp_dir().'/bug98-public-'.uniqid();
    mkdir($directory);

    try {
        file_put_contents($directory.'/hot', "http://127.0.0.1:5173\n");
        $this->app->usePublicPath($directory);
        Route::get('/csp-probe', fn () => response('ok'));

        expect(Vite::isRunningHot())->toBeTrue();

        $this->withoutVite();

        expect(app(FoundationVite::class)::class)->not->toBe(FoundationVite::class)
            ->and(Vite::isRunningHot())->toBeFalse()
            ->and(Vite::hotFile())->toBe(TestCase::NO_VITE_HOT_FILE);

        $response = $this->get('/csp-probe')->assertOk();

        expect($response->headers->get('Content-Security-Policy'))
            ->toMatch("/^frame-ancestors 'none'; base-uri 'self'; object-src 'none'; script-src 'self' 'nonce-[A-Za-z0-9]{40}' 'unsafe-eval'; worker-src 'self' blob:; report-uri \/csp-report\z/");
    } finally {
        @unlink($directory.'/hot');
        @rmdir($directory);
    }
});

it('keeps a CSP the response already sets and adds no report-only header', function () {
    Route::get('/csp-probe', fn () => response('ok')->header('Content-Security-Policy', "default-src 'none'"));

    $response = $this->get('/csp-probe')->assertHeader('Content-Security-Policy', "default-src 'none'");

    $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
});

/**
 * @return list<string>
 */
function scriptsMissingTheNonce(string $html, string $nonce): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $missing = [];

    foreach ($document->querySelectorAll('script') as $script) {
        $type = strtolower(trim((string) $script->getAttribute('type')));

        if (in_array($type, ['application/json', 'application/ld+json'], true)) {
            continue;
        }

        if ($script->getAttribute('nonce') === $nonce) {
            continue;
        }

        $src = $script->getAttribute('src');

        if ($src !== null && str_starts_with($src, url('/').'/')) {
            continue;
        }

        $missing[] = $src !== null ? 'script[src='.$src.']' : 'script['.substr(trim((string) $script->textContent), 0, 60).']';
    }

    return $missing;
}

/**
 * @return list<string>
 */
function inlineEventHandlers(string $html): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $handlers = [];

    foreach ($document->querySelectorAll('*') as $element) {
        foreach ($element->attributes as $attribute) {
            if (preg_match('/^on[a-z]+$/', $attribute->name) === 1) {
                $handlers[] = strtolower($element->localName).'@'.$attribute->name;
            }
        }
    }

    return $handlers;
}

describe('every inline script carries the request nonce', function () {
    beforeEach(function () {
        $this->withoutVite();
        $this->cspOwner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'csp.owner@example.com']);
        $this->cspOrg = $this->cspOwner->personalOrganization();
        $this->cspOrg->update(['name' => 'CSP Org']);
        $this->cspContact = Contact::factory()->create(['organization_id' => $this->cspOrg->id, 'name' => 'Ada Csp']);
    });

    it('files no script without the nonce and no inline event handler', function (string $who, Closure $url) {
        if ($who === 'owner') {
            $this->actingAs($this->cspOwner);
            Filament::setTenant($this->cspOrg);
        }

        $response = $this->get($url($this))->assertOk();
        $nonce = cspNonce($response->headers->get('Content-Security-Policy'));

        expect(scriptsMissingTheNonce($response->getContent(), $nonce))->toBe([])
            ->and(inlineEventHandlers($response->getContent()))->toBe([]);
    })->with([
        'landing' => ['guest', fn () => '/'],
        'privacy' => ['guest', fn () => route('privacy')],
        'panel login' => ['guest', fn () => Filament::getLoginUrl()],
        'forgot password' => ['guest', fn () => route('password.request')],
        'dashboard' => ['owner', fn () => Dashboard::getUrl()],
        'contacts index' => ['owner', fn () => ContactResource::getUrl('index')],
        'contact view' => ['owner', fn ($t) => ContactResource::getUrl('view', ['record' => $t->cspContact])],
        'contact edit' => ['owner', fn ($t) => ContactResource::getUrl('edit', ['record' => $t->cspContact])],
        'panel profile' => ['owner', fn () => Filament::getProfileUrl()],
        'flux profile' => ['owner', fn () => route('profile.edit')],
        'inertia tags' => ['owner', fn ($t) => route('app.tags.index', $t->cspOrg)],
    ]);

    it('puts the nonce on the three Filament scripts seen in HT127', function () {
        $this->actingAs($this->cspOwner);
        Filament::setTenant($this->cspOrg);

        $response = $this->get(Dashboard::getUrl())->assertOk();
        $nonce = cspNonce($response->headers->get('Content-Security-Policy'));
        $html = $response->getContent();
        $open = '<script nonce="'.$nonce.'">\s*';

        expect($html)->toMatch('/'.$open.'const loadDarkMode = \(\) => \{/')
            ->and($html)->toMatch('/'.$open.'var collapsedGroups = JSON\.parse\(/')
            ->and($html)->toMatch('/'.$open.'loadDarkMode\(\)\s*<\/script>/');
    });

    it('puts the nonce on the Flux appearance script', function () {
        $this->actingAs($this->cspOwner);

        $response = $this->get(route('profile.edit'))->assertOk();
        $nonce = cspNonce($response->headers->get('Content-Security-Policy'));

        expect($response->getContent())->toMatch('/<script nonce="'.$nonce.'">\s*window\.Flux = \{/');
    });

    it('never hands the nonce to a script a hostile contact name tries to inject', function () {
        $hostile = Contact::factory()->create(['organization_id' => $this->cspOrg->id, 'name' => '<script>alert(1)</script> Csp']);
        $this->actingAs($this->cspOwner);
        Filament::setTenant($this->cspOrg);

        foreach ([ContactResource::getUrl('view', ['record' => $hostile]), ContactResource::getUrl('index')] as $url) {
            $response = $this->get($url)->assertOk();
            $nonce = cspNonce($response->headers->get('Content-Security-Policy'));
            $document = HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);

            foreach ($document->querySelectorAll('script') as $script) {
                expect($script->textContent)->not->toContain('alert(1)');
            }

            expect(scriptsMissingTheNonce($response->getContent(), $nonce))->toBe([]);
        }
    });

    it('detects a script without the nonce and an inline handler', function () {
        $html = '<html><body><script>x()</script><script nonce="abc">y()</script><script type="application/json">{}</script><img src="a" onerror="x()"></body></html>';

        expect(scriptsMissingTheNonce($html, 'abc'))->toBe(['script[x()]'])
            ->and(inlineEventHandlers($html))->toBe(['img@onerror']);
    });
});
