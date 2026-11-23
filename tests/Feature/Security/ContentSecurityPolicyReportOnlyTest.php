<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\User;
use Dom\HTMLDocument;
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
        $nonce = cspReportOnlyNonce($response->headers->get('Content-Security-Policy-Report-Only'));

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
        $nonce = cspReportOnlyNonce($response->headers->get('Content-Security-Policy-Report-Only'));
        $html = $response->getContent();
        $open = '<script nonce="'.$nonce.'">\s*';

        expect($html)->toMatch('/'.$open.'const loadDarkMode = \(\) => \{/')
            ->and($html)->toMatch('/'.$open.'var collapsedGroups = JSON\.parse\(/')
            ->and($html)->toMatch('/'.$open.'loadDarkMode\(\)\s*<\/script>/');
    });

    it('puts the nonce on the Flux appearance script', function () {
        $this->actingAs($this->cspOwner);

        $response = $this->get(route('profile.edit'))->assertOk();
        $nonce = cspReportOnlyNonce($response->headers->get('Content-Security-Policy-Report-Only'));

        expect($response->getContent())->toMatch('/<script nonce="'.$nonce.'">\s*window\.Flux = \{/');
    });

    it('never hands the nonce to a script a hostile contact name tries to inject', function () {
        $hostile = Contact::factory()->create(['organization_id' => $this->cspOrg->id, 'name' => '<script>alert(1)</script> Csp']);
        $this->actingAs($this->cspOwner);
        Filament::setTenant($this->cspOrg);

        foreach ([ContactResource::getUrl('view', ['record' => $hostile]), ContactResource::getUrl('index')] as $url) {
            $response = $this->get($url)->assertOk();
            $nonce = cspReportOnlyNonce($response->headers->get('Content-Security-Policy-Report-Only'));
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
