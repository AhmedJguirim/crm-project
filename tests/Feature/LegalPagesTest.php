<?php

use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->withoutVite();
});

it('serves a legal page to a guest', function (string $route, string $heading, string $title, string $firstSection) {
    $html = $this->get(route($route))->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain($heading)
        ->and($html)->toContain("<title>{$title}</title>")
        ->and($html)->toContain('Last updated:')
        ->and($html)->toContain('<p>Last updated: 6 November 2026</p>')
        ->and($html)->toContain("<h2>{$firstSection}</h2>")
        ->and($html)->toContain('<main id="main"')
        ->and($html)->toContain('<link rel="canonical" href="'.url($route).'">')
        ->and($html)->toContain('<meta property="og:url" content="'.url($route).'">')
        ->and($html)->not->toContain('<link rel="preload" as="image"')
        ->and($html)->toContain('href="'.route('home').'#features"')
        ->and($html)->toContain('href="'.route('privacy').'"')
        ->and($html)->toContain('href="'.route('terms').'"');
})->with([
    'privacy' => ['privacy', 'Privacy Policy', 'Privacy Policy — CRM', 'Who we are'],
    'terms' => ['terms', 'Terms of Service', 'Terms of Service — CRM', 'The service'],
]);

it('serves the legal pages to a logged-in user without a redirect', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    foreach (['privacy', 'terms'] as $route) {
        $this->actingAs($user)->get(route($route))->assertOk()->assertSee('Open the app');
    }
});

it('uses the agreed urls', function () {
    expect(route('privacy'))->toBe(url('privacy'))
        ->and(route('terms'))->toBe(url('terms'));
});

it('keeps the agreed facts in the draft text', function () {
    $privacy = file_get_contents(resource_path('markdown/privacy.md'));
    $terms = file_get_contents(resource_path('markdown/terms.md'));

    expect($privacy)->toContain('Last updated:', 'OVH', 'XSRF-TOKEN', 'processor')
        ->and($terms)->toContain('Last updated:', 'Art. 28')
        ->and($privacy)->not->toMatch('/^# /m')
        ->and($terms)->not->toMatch('/^# /m');
});

it('links the legal pages from the landing page and keeps its own anchors', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('href="'.route('privacy').'"')
        ->and($html)->toContain('href="'.route('terms').'"')
        ->and($html)->toContain('>Privacy Policy</a>')
        ->and($html)->toContain('>Terms of Service</a>')
        ->and($html)->toContain('href="#features"')
        ->and($html)->toContain('<link rel="canonical" href="'.url('/').'">')
        ->and(substr_count($html, '<link rel="preload" as="image"'))->toBe(1);
});

it('shows the required terms checkbox on the sign-up page', function () {
    $html = $this->get(Filament::getRegistrationUrl())->assertOk()->getContent();

    expect($html)->toContain('I agree to the')
        ->and($html)->toContain('and have read the')
        ->and($html)->not->toContain('By signing up, you agree to the')
        ->and($html)->toContain('<a href="'.route('terms').'" target="_blank"')
        ->and($html)->toContain('<a href="'.route('privacy').'" target="_blank"');
});

it('links the legal pages from both login pages', function (Closure $url) {
    $html = $this->followingRedirects()->get($url())->assertOk()->getContent();

    expect($html)->toContain('href="'.route('terms').'" target="_blank"')
        ->and($html)->toContain('href="'.route('privacy').'" target="_blank"')
        ->and($html)->toContain('rel="noopener noreferrer"');
})->with([
    'panel login' => [fn () => Filament::getLoginUrl()],
    'old /login, redirected' => [fn () => route('login')],
]);
