<?php

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->ann = User::factory()->onboardingCompleted()->create(['email' => 'ann@example.test']);
});

test('a signed-in user opening an old settings url lands on the panel profile', function (string $path) {
    $this->actingAs($this->ann)->get($path)
        ->assertStatus(302)
        ->assertRedirect(Filament::getProfileUrl());
})->with([
    '/settings',
    '/settings/profile',
    '/settings/password',
    '/settings/notifications',
    '/settings/appearance',
    '/settings/two-factor',
]);

test('a guest opening an old settings url is sent to the panel profile, which asks for the login', function () {
    $this->get('/settings/profile')->assertRedirect(Filament::getProfileUrl());
    $this->get(Filament::getProfileUrl())->assertRedirect(Filament::getLoginUrl());
});

test('an unverified user opening an old settings url reaches the panel profile', function () {
    $this->ann->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($this->ann)->followingRedirects()->get('/settings/profile')->assertOk();
});

test('the old route names are gone and the email confirm route stays', function () {
    expect(Route::has('profile.edit'))->toBeFalse()
        ->and(Route::has('user-password.edit'))->toBeFalse()
        ->and(Route::has('notification-settings.edit'))->toBeFalse()
        ->and(Route::has('user-email.confirm'))->toBeTrue();
});

test('the email change confirm path is not redirected', function () {
    $this->actingAs($this->ann)
        ->get('/settings/email/confirm/abc/da39a3ee5e6b4b0d3255bfef95601890afd80709')
        ->assertNotFound();
});

test('a crafted old settings url cannot redirect anywhere else', function (string $path) {
    $response = $this->get($path)->assertStatus(302);

    expect($response->headers->get('Location'))->toBe(Filament::getProfileUrl());
})->with([
    '/settings/https://evil.example',
    '/settings//evil.example',
    '/settings/profile?redirect=https://evil.example',
]);

test('posting to an old settings url is refused cleanly', function () {
    $this->post('/settings/profile')->assertStatus(405);
});
