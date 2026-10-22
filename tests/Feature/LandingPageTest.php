<?php

use App\Models\User;
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
