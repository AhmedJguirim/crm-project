<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->withoutVite();
});

test('a guest sees the landing page instead of a redirect', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Your clients, deals and invoices,', false);
    $response->assertSee('in one place.', false);
    $response->assertSee('CRM for consultants, freelancers and small sales teams', false);
    $response->assertSee('<html lang="en">', false);
    $response->assertSee('<meta name="theme-color" content="#2563eb">', false);
    expect(substr_count($response->getContent(), '<h1'))->toBe(1);
});

test('a guest gets the panel login and registration links, never the Fortify ones', function () {
    $panel = Filament::getPanel('admin');

    $response = $this->get('/');

    $response->assertSee('href="'.$panel->getLoginUrl().'"', false);
    $response->assertSee('href="'.$panel->getRegistrationUrl().'"', false);
    $response->assertSee('Log in');
    $response->assertSee('Get started');
    $response->assertDontSee('href="'.route('login').'"', false);
    $response->assertDontSee('Open the app');
});

test('a logged-in user sees Open the app and no sign-up button', function () {
    $user = User::factory()->withPersonalOrganization()->create();
    $this->actingAs($user);
    $appUrl = Filament::getPanel('admin')->getUrl();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Open the app');
    $response->assertSee('href="'.$appUrl.'"', false);
    expect($appUrl)->toStartWith(url('/admin'));
    $response->assertDontSee('Get started');
    $response->assertDontSee('Log in');
});

test('a guest request runs no database query', function () {
    DB::enableQueryLog();

    $this->get('/')->assertOk();

    expect(DB::getQueryLog())->toBeEmpty();
});

test('the page ships no Livewire, Filament or Flux asset and no Alpine attribute', function () {
    $content = $this->get('/')->assertOk()->getContent();

    expect($content)
        ->not->toContain('livewire')
        ->not->toContain('filament')
        ->not->toContain('flux')
        ->not->toContain('x-data');
});

test('the theme toggle and the no-flash script are present', function () {
    $response = $this->get('/');

    $response->assertSee('data-theme-toggle', false);
    $response->assertSee('aria-label="Toggle dark mode"', false);
    $response->assertSee("localStorage.getItem('theme')", false);
});

test('the home route keeps its name', function () {
    expect(route('home'))->toBe(url('/'));
});

test('a guest sees the navbar links, the drawer and the guest CTAs', function () {
    $panel = Filament::getPanel('admin');
    $loginUrl = $panel->getLoginUrl();
    $registerUrl = $panel->getRegistrationUrl();

    $response = $this->get('/')->assertOk();
    $content = $response->getContent();

    foreach ([
        'data-landing-navbar',
        'data-scrolled="false"',
        '<nav aria-label="Main"',
        'href="#features"',
        'href="#pipeline"',
        'href="#reports"',
        'href="#teams"',
        'id="mobile-menu"',
        'role="dialog"',
        'aria-modal="true"',
        'aria-controls="mobile-menu"',
        'aria-expanded="false"',
        'aria-label="Open menu"',
        'aria-label="Close menu"',
        'data-menu-open',
        'data-menu-backdrop',
    ] as $needle) {
        $response->assertSee($needle, false);
    }

    foreach (['Features', 'Pipeline', 'Reports', 'Teams'] as $label) {
        $response->assertSee($label);
    }

    expect(substr_count($content, 'href="'.$loginUrl.'"'))->toBe(3)
        ->and(substr_count($content, 'href="'.$registerUrl.'"'))->toBe(4)
        ->and(substr_count($content, 'data-theme-toggle'))->toBe(1)
        ->and(substr_count($content, 'href="#features"'))->toBe(5)
        ->and(substr_count($content, 'href="#pipeline"'))->toBe(3)
        ->and(substr_count($content, 'href="#reports"'))->toBe(3)
        ->and(substr_count($content, 'href="#teams"'))->toBe(3)
        ->and(substr_count($content, 'href="#import"'))->toBe(1);
});

test('the hero has the headline, the lead and both CTAs', function () {
    $response = $this->get('/')->assertOk();

    $response->assertSee('<section id="top"', false);
    $response->assertSee('Your clients, deals and invoices,', false);
    $response->assertSee('in one place.', false);
    $response->assertSee('without a spreadsheet in sight.', false);
    $response->assertSee('See the features');
    $response->assertSee('aria-label="Scroll to the features"', false);
    $response->assertSee('data-hero-visual', false);
    expect(substr_count($response->getContent(), '<h1'))->toBe(1);
});

test('the footer shows the brand, the links and the copyright only', function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 12:00:00', 'UTC'));

    $response = $this->get('/')->assertOk();

    expect(substr_count($response->getContent(), '<footer'))->toBe(1);
    $response->assertSee('A simple CRM for consultants, freelancers and small sales teams.');
    $response->assertSee('Product');
    $response->assertSee('Account');
    $response->assertSee('Import and export');
    $response->assertSee('href="#import"', false);
    $response->assertSee('Sign up');
    $response->assertSee('© 2027 CRM');
});

test('a logged-in user gets Open the app everywhere and no guest CTA', function () {
    $user = User::factory()->withPersonalOrganization()->create();
    $this->actingAs($user);
    $panel = Filament::getPanel('admin');
    $appUrl = $panel->getUrl();

    $response = $this->get('/')->assertOk();

    expect(substr_count($response->getContent(), 'href="'.$appUrl.'"'))->toBe(4);
    $response->assertDontSee('Sign up');
    $response->assertDontSee('Get started');
    $response->assertDontSee('Log in');
    $response->assertDontSee('href="'.$panel->getRegistrationUrl().'"', false);
    $response->assertDontSee('href="'.$panel->getLoginUrl().'"', false);
    $response->assertSee('See the features');
});

test('the page ships no Alpine and no forbidden script attributes', function () {
    $content = $this->get('/')->assertOk()->getContent();

    expect($content)
        ->not->toContain('x-data')
        ->not->toContain('x-show')
        ->not->toContain('@click');
});
