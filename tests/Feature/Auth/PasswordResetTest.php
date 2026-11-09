<?php

use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
});

// BUG-65: a user stored with capitals gets a link and can reset with the address in any case
test('a user stored with capitals gets a reset link and can reset with another case', function () {
    Notification::fake();

    $ann = User::factory()->create(['email' => 'Ann.Lee@Example.com']);

    $this->post(route('password.email'), ['email' => 'ANN.LEE@example.com']);

    Notification::assertSentTo($ann, QueuedResetPassword::class, function ($notification) {
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'Ann.Lee@Example.com']);

        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => 'ann.lee@example.com',
            'password' => 'N3w-passw0rd!',
            'password_confirmation' => 'N3w-passw0rd!',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });

    expect(Hash::check('N3w-passw0rd!', $ann->fresh()->password))->toBeTrue();
});

// BUG-66: a forged array email is a validation error, not a 500
test('the reset password form answers a forged array email with a validation error', function () {
    $ann = User::factory()->create(['email' => 'ann@example.com']);

    $this->post(route('password.update'), [
        'token' => 'any-token',
        'email' => ['ann@example.com'],
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])
        ->assertStatus(302)
        ->assertSessionHasErrors(['email' => 'The email field must be a string.']);

    expect(Hash::check('password', $ann->fresh()->password))->toBeTrue();
});

test('the forgot password form still answers an array email with a validation error', function () {
    User::factory()->create(['email' => 'ann@example.com']);

    $this->post(route('password.email'), ['email' => ['ann@example.com']])
        ->assertStatus(302)
        ->assertSessionHasErrors(['email' => 'The email field must be a valid email address.']);
});

test('the reset password form keeps answering a missing email with the required error', function () {
    $this->post(route('password.update'), [
        'token' => 'any-token',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasErrors(['email' => 'The email field is required.']);
});
