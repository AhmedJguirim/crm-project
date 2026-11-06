<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

// AC-001: Successful registration with valid data
test('registration screen can be rendered', function () {
    $this->get(Filament::getRegistrationUrl())
        ->assertOk();
});

test('new users can register with valid data', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Ahmed Test',
            'email' => 'ahmed.test@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticated();

    $user = User::where('email', 'ahmed.test@example.com')->first();

    expect($user)
        ->not->toBeNull()
        ->name->toBe('Ahmed Test')
        ->onboarding_completed->toBeFalse();

    expect($user->password)->not->toBe('password');
});

// AC-002: Registration with validation errors
test('registration requires name', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => '',
            'email' => 'ahmed.test@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasFormErrors(['name' => 'required']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('registration requires email', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Ahmed Test',
            'email' => '',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasFormErrors(['email' => 'required']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('registration requires valid email format', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Ahmed Test',
            'email' => 'not-an-email',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('registration requires password of at least 8 characters', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Ahmed Test',
            'email' => 'ahmed.test@example.com',
            'password' => 'short',
            'passwordConfirmation' => 'short',
        ])
        ->call('register')
        ->assertHasFormErrors(['password']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('registration requires password confirmation to match', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Ahmed Test',
            'email' => 'ahmed.test@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'different-password',
        ])
        ->call('register')
        ->assertHasFormErrors(['password']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

// AC-003: Registration with duplicate email
test('registration fails with duplicate email', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Ahmed Test',
            'email' => 'existing@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasFormErrors(['email' => 'unique']);

    $this->assertGuest();
    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
});

// AC-004: Rate limiting on excessive attempts
test('registration is rate limited after too many attempts', function () {
    $component = Livewire::test(Register::class);

    for ($i = 0; $i < 2; $i++) {
        $component
            ->fillForm([
                'name' => 'Ahmed Test',
                'email' => "ahmed{$i}@example.com",
                'password' => 'password',
                'passwordConfirmation' => 'password',
            ])
            ->call('register');
    }

    $component
        ->fillForm([
            'name' => 'Ahmed Test',
            'email' => 'throttled@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertNotified();

    expect(User::where('email', 'throttled@example.com')->exists())->toBeFalse();
});

// Edge case: extremely long name
test('registration rejects name longer than 255 characters', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => str_repeat('a', 256),
            'email' => 'ahmed.test@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasFormErrors(['name' => 'max']);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

// SEC-18: emails are stored lower-case
test('registering with capitals stores the email lower-case and the user can log in with any case', function () {
    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Bob Smith',
            'email' => 'Bob.Smith@Example.COM',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::query()->pluck('email')->all())->toBe(['bob.smith@example.com']);

    auth()->logout();

    Livewire::test(Login::class)
        ->fillForm(['email' => 'Bob.Smith@Example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticated();
});

test('the same address in another case is already taken and creates no second user', function () {
    User::factory()->create(['email' => 'bob@example.com']);

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Bob Two',
            'email' => 'BOB@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
    expect(User::query()->whereRaw('lower(email) = ?', ['bob@example.com'])->count())->toBe(1);
});

test('the registration email is left as typed when lower-casing is turned off', function () {
    config(['fortify.lowercase_usernames' => false]);

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Bob Smith',
            'email' => 'Bob@Example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(User::query()->pluck('email')->all())->toBe(['Bob@Example.com']);
});
