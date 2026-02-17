<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

// AC-001: Successful login with correct credentials
test('login screen can be rendered', function () {
    $this->get(Filament::getLoginUrl())
        ->assertOk();
});

test('users can authenticate with correct credentials', function () {
    $user = User::factory()->onboardingCompleted()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticated();
});

test('first-time login sets onboarding session flag', function () {
    $user = User::factory()->onboardingNotCompleted()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticated();
    expect(session('show_onboarding'))->toBeTrue();
});

test('returning user login does not set onboarding session flag', function () {
    $user = User::factory()->onboardingCompleted()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticated();
    expect(session('show_onboarding'))->toBeNull();
});

test('authenticated user can access protected routes', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->actingAs($user)
        ->get(Filament::getUrl($user->personalOrganization()))
        ->assertOk();
});

// AC-002: Failed login with invalid credentials
test('users cannot authenticate with invalid password', function () {
    $user = User::factory()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

test('users cannot authenticate with non-existent email', function () {
    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'nonexistent@example.com',
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

// AC-003: Login with empty fields
test('login requires email', function () {
    Livewire::test(Login::class)
        ->fillForm([
            'email' => '',
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['email' => 'required']);

    $this->assertGuest();
});

test('login requires password', function () {
    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'test@example.com',
            'password' => '',
        ])
        ->call('authenticate')
        ->assertHasFormErrors(['password' => 'required']);

    $this->assertGuest();
});

// AC-004: Rate limiting on excessive failed attempts
test('login is rate limited after too many failed attempts', function () {
    $user = User::factory()->create();

    $component = Livewire::test(Login::class);

    for ($i = 0; $i < 5; $i++) {
        $component
            ->fillForm([
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->call('authenticate');
    }

    $component
        ->fillForm([
            'email' => $user->email,
            'password' => 'wrong-password',
        ])
        ->call('authenticate')
        ->assertNotified();

    $this->assertGuest();
});

// AC-005: "Remember me" functionality
test('remember me checkbox is present on login form', function () {
    Livewire::test(Login::class)
        ->assertFormFieldExists('remember');
});

test('user can login with remember me checked', function () {
    $user = User::factory()->onboardingCompleted()->create();

    Livewire::test(Login::class)
        ->fillForm([
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticated();
});

// Edge case: case-insensitive email matching
test('login handles case-insensitive email', function () {
    $user = User::factory()->create(['email' => 'test@example.com']);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'TEST@EXAMPLE.COM',
            'password' => 'password',
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticated();
});
