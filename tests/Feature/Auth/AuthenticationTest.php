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

// BUG-65: a user stored with capitals can log in with the address in any case
test('the Filament login finds a user stored with capitals in any case', function (string $typed) {
    $ann = User::factory()->onboardingCompleted()->create(['email' => 'Ann.Lee@Example.com']);

    Livewire::test(Login::class)
        ->fillForm(['email' => $typed, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticatedAs($ann);
})->with(['ann.lee@example.com', 'ANN.LEE@EXAMPLE.COM', 'Ann.Lee@Example.com']);

test('a wrong password is still refused for a user stored with capitals', function () {
    User::factory()->onboardingCompleted()->create(['email' => 'Ann.Lee@Example.com']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'ann.lee@example.com', 'password' => 'wrong-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

test('the exact-case row wins when two rows differ only by case', function () {
    User::factory()->onboardingCompleted()->create(['email' => 'Ann.Lee@Example.com']);
    $lower = User::factory()->onboardingCompleted()->create(['email' => 'ann.lee@example.com', 'password' => 'other-password']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'ANN.LEE@example.com', 'password' => 'other-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($lower);
});

test('GET /login redirects to the panel login', function () {
    $this->get('/login')->assertRedirect(Filament::getLoginUrl());
});

test('the old login POST signs nobody in, even with the right password', function () {
    User::factory()->onboardingCompleted()->create(['email' => 'Ann.Lee@Example.com']);

    $this->post('/login', ['email' => 'ANN.LEE@example.com', 'password' => 'password'])
        ->assertStatus(303)
        ->assertRedirect(Filament::getLoginUrl());

    $this->assertGuest();
});

test('a lookup credential that is not a string is passed on unchanged and finds nobody', function () {
    User::factory()->create(['email' => 'Ann.Lee@Example.com']);

    expect(auth()->guard('web')->getProvider()->retrieveByCredentials(['email' => ['Ann.Lee@Example.com'], 'password' => 'x']))
        ->toBeInstanceOf(User::class)
        ->and(auth()->guard('web')->getProvider()->retrieveByCredentials(['email' => 'Ann.Lee@Example.com%', 'password' => 'x']))
        ->toBeNull();
});

// BUG-66: a forged array email is not a 500
test('the old login POST answers a forged array email with a redirect', function () {
    User::factory()->create(['email' => 'ann@example.com']);

    $this->post('/login', ['email' => ['ann@example.com'], 'password' => 'password'])
        ->assertStatus(303)
        ->assertRedirect(Filament::getLoginUrl());

    $this->assertGuest();
});

test('GET /login sends a signed-in user away from the login', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $response = $this->actingAs($user)->get('/login');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toBe(Filament::getLoginUrl());
});
