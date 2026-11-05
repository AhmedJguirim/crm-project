<?php

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

const PASSWORD_RESET_GENERIC_STATUS = 'If an account exists for this email address, we have emailed a password reset link.';

function postResetLinkFor(string $email, string $ip = '127.0.0.1')
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->from(route('password.request'))
        ->post(route('password.email'), ['email' => $email]);
}

function postBogusResetFor(string $email, string $ip = '127.0.0.1')
{
    return test()
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->from(route('password.request'))
        ->post(route('password.update'), [
            'token' => 'bogus-token',
            'email' => $email,
            'password' => 'N3w-passw0rd!',
            'password_confirmation' => 'N3w-passw0rd!',
        ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00'));
    Notification::fake();
    $this->ann = User::factory()->create(['email' => 'ann@example.com']);
});

test('forgot-password gives the same answer for known, unknown and differently cased addresses', function (string $email, bool $sent) {
    postResetLinkFor($email)
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', PASSWORD_RESET_GENERIC_STATUS);

    if ($sent) {
        Notification::assertSentTo($this->ann, ResetPassword::class);

        return;
    }

    Notification::assertNothingSent();
})->with([
    'known' => ['ann@example.com', true],
    'unknown' => ['nobody@example.com', false],
    'upper case' => ['ANN@example.com', true],
]);

test('a second request inside the broker throttle looks the same', function () {
    postResetLinkFor('ann@example.com')->assertSessionHas('status', PASSWORD_RESET_GENERIC_STATUS);

    $this->travel(10)->seconds();

    postResetLinkFor('ann@example.com')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', PASSWORD_RESET_GENERIC_STATUS);

    Notification::assertSentToTimes($this->ann, ResetPassword::class, 1);
});

test('reset-password gives the same error for known and unknown emails', function (string $email) {
    postBogusResetFor($email)
        ->assertSessionHasErrors(['email' => 'This password reset token is invalid.']);
})->with(['known' => 'ann@example.com', 'unknown' => 'nobody@example.com']);

test('forgot-password is limited to 5 a minute per IP', function () {
    foreach (range(1, 5) as $i) {
        postResetLinkFor("nobody{$i}@example.com")
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status', PASSWORD_RESET_GENERIC_STATUS);
    }

    postResetLinkFor('nobody6@example.com')->assertStatus(429);
    postResetLinkFor('nobody7@example.com', '10.0.0.2')->assertRedirect(route('password.request'));
});

test('forgot-password is limited to 20 an hour per IP', function () {
    foreach (range(1, 4) as $batch) {
        foreach (range(1, 5) as $i) {
            postResetLinkFor("nobody{$batch}-{$i}@example.com")->assertRedirect(route('password.request'));
        }

        $this->travel(61)->seconds();
    }

    postResetLinkFor('nobody-21@example.com')->assertStatus(429);
});

test('reset-password is limited to 5 a minute per IP', function () {
    foreach (range(1, 5) as $i) {
        postBogusResetFor("nobody{$i}@example.com")->assertSessionHasErrors('email');
    }

    postBogusResetFor('nobody6@example.com')->assertStatus(429);
});

test('the forgot-password page and the reset form are not limited', function () {
    foreach (range(1, 8) as $i) {
        $this->get(route('password.request'))->assertOk();
        $this->get(route('password.reset', 'some-token'))->assertOk();
    }
});

test('the Fortify login is limited to 10 a minute per IP across emails', function () {
    foreach (range(1, 10) as $i) {
        $this->post('/login', ['email' => "nobody{$i}@example.com", 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => 'nobody11@example.com', 'password' => 'wrong-password'])->assertStatus(429);
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->post('/login', ['email' => 'nobody12@example.com', 'password' => 'wrong-password'])
        ->assertSessionHasErrors('email');
});

test('the Fortify login keeps its limit per email and IP', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => 'ann@example.com', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
    }

    $this->post('/login', ['email' => 'ann@example.com', 'password' => 'wrong-password'])->assertStatus(429);
});
