<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Support\Security\LoginLimits;
use Filament\Notifications\Notification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const ACCOUNT_LIMIT_FAILED_MESSAGE = 'These credentials do not match our records.';

/**
 * One password step of the panel login, with Filament's per-IP counter cleared first so each attempt looks like it
 * comes from a fresh address and only the per-account limit can refuse it.
 */
function accountLimitAttempt(mixed $email, mixed $password): Testable
{
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|'.request()->ip()));

    return Livewire::test(Login::class)
        ->set('data.email', $email)
        ->set('data.password', $password)
        ->call('authenticate');
}

function accountLimitFail(string $email, int $times, string $password = 'wrong'): void
{
    foreach (range(1, $times) as $ignored) {
        accountLimitAttempt($email, $password)->assertHasErrors(['data.email' => ACCOUNT_LIMIT_FAILED_MESSAGE]);
    }
}

function accountLimitNotification(string $body): Notification
{
    return Notification::make()->title('Too many login attempts')->body($body)->danger();
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00:00', 'UTC'));
    $this->victim = User::factory()->onboardingCompleted()->create(['email' => 'victim@example.com', 'password' => 'correct-horse-1']);
    $this->bystander = User::factory()->onboardingCompleted()->create(['email' => 'bystander@example.com', 'password' => 'correct-horse-2']);
});

test('10 failures from different IPs lock the email, even for the right password', function () {
    accountLimitFail('victim@example.com', 10);

    accountLimitAttempt('victim@example.com', 'correct-horse-1')
        ->assertNotified(accountLimitNotification('Please try again in 15 minutes.'))
        ->assertHasNoErrors();

    $this->assertGuest();
});

test('the per-IP limit is cleared between attempts, so the 6th failure in a minute still reaches the password check', function () {
    accountLimitFail('victim@example.com', 6);

    expect(LoginLimits::isLocked('victim@example.com'))->toBeFalse();
});

test('the minutes left are rounded up', function (string $time, string $body) {
    accountLimitFail('victim@example.com', 10);
    $this->travelTo(Carbon::parse($time, 'UTC'));

    accountLimitAttempt('victim@example.com', 'correct-horse-1')->assertNotified(accountLimitNotification($body));
    $this->assertGuest();
})->with([
    ['2026-11-13 10:01:00', 'Please try again in 14 minutes.'],
    ['2026-11-13 10:06:00', 'Please try again in 9 minutes.'],
    ['2026-11-13 10:14:30', 'Please try again in 1 minute.'],
]);

test('the lock ends after the window', function () {
    accountLimitFail('victim@example.com', 10);
    $this->travelTo(Carbon::parse('2026-11-13 10:15:01', 'UTC'));

    accountLimitAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->victim);
});

test('the window starts at the first failure, not at the last', function () {
    accountLimitFail('victim@example.com', 1);
    $this->travelTo(Carbon::parse('2026-11-13 10:14:00', 'UTC'));
    accountLimitFail('victim@example.com', 9);

    accountLimitAttempt('victim@example.com', 'correct-horse-1')->assertNotified(accountLimitNotification('Please try again in 1 minute.'));

    $this->travelTo(Carbon::parse('2026-11-13 10:15:01', 'UTC'));
    accountLimitAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();
    $this->assertAuthenticatedAs($this->victim);
});

test('9 failures do not lock, and a success resets the count', function () {
    accountLimitFail('victim@example.com', 9);
    accountLimitAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();
    $this->assertAuthenticatedAs($this->victim);
    auth()->logout();

    accountLimitFail('victim@example.com', 9);
    accountLimitAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->victim);
});

test('case and spaces share one counter', function () {
    accountLimitFail('Victim@Example.com', 5);
    accountLimitFail('victim@example.com', 5);

    accountLimitAttempt('VICTIM@example.com', 'correct-horse-1')->assertNotified('Too many login attempts');

    $this->assertGuest();
});

test('another account is not affected', function () {
    accountLimitFail('victim@example.com', 10);

    accountLimitAttempt('bystander@example.com', 'correct-horse-2')->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->bystander);
});

test('a completed password reset unlocks the email', function () {
    accountLimitFail('victim@example.com', 10);
    expect(LoginLimits::isLocked('victim@example.com'))->toBeTrue();

    event(new PasswordReset($this->victim));
    accountLimitAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->victim);
});

test('a password reset of another account does not unlock the email', function () {
    accountLimitFail('victim@example.com', 10);

    event(new PasswordReset($this->bystander));

    expect(LoginLimits::isLocked('victim@example.com'))->toBeTrue();
});

test('the lockout is logged once, with the target account and without the email', function () {
    $log = fakeSecurityLog();

    accountLimitFail('victim@example.com', 10);
    foreach (range(1, 3) as $ignored) {
        accountLimitAttempt('victim@example.com', 'correct-horse-1');
    }

    $entries = securityLogEntries($log);
    expect(collect($entries)->pluck('event')->all())->toBe([...array_fill(0, 10, 'auth.login_failed'), 'auth.lockout']);

    $lockout = $entries[10];
    expect($lockout['level'])->toBe('warning');
    expect($lockout['context'])->toMatchArray(['source' => 'panel_account', 'user_id' => $this->victim->id]);

    $dump = json_encode($entries);
    expect($dump)->not->toContain('victim@example.com')->not->toContain('wrong')->not->toContain('correct-horse-1');
});

test('an attacker probing an unknown email sees exactly what a real account shows', function () {
    $log = fakeSecurityLog();

    accountLimitFail('nobody@example.com', 10);
    accountLimitAttempt('nobody@example.com', 'anything')
        ->assertNotified(accountLimitNotification('Please try again in 15 minutes.'));

    $lockouts = array_values(array_filter(securityLogEntries($log), fn (array $entry): bool => $entry['event'] === 'auth.lockout'));
    expect($lockouts)->toHaveCount(1);
    expect($lockouts[0]['context'])->toMatchArray(['source' => 'panel_account', 'user_id' => null]);
});

test('a refused attempt during the lock does not count and does not touch the password check', function () {
    accountLimitFail('victim@example.com', 10);
    $this->travelTo(Carbon::parse('2026-11-13 10:10:00', 'UTC'));

    foreach (range(1, 5) as $ignored) {
        accountLimitAttempt('victim@example.com', 'wrong');
    }

    expect(LoginLimits::secondsUntilUnlocked('victim@example.com'))->toBe(300);
});

test('a forged non-string email is a validation error, not a 500, and creates no counter', function () {
    config(['app.debug' => true]);

    $page = Livewire::test(Login::class);
    $page->set('data.email', ['victim@example.com'])->set('data.password', 'wrong')->call('authenticate');

    expect(LoginLimits::isLocked('victim@example.com'))->toBeFalse();
    $page->assertHasErrors(['data.email']);

    accountLimitFail('victim@example.com', 9);
    expect(LoginLimits::isLocked('victim@example.com'))->toBeFalse();
});

test('the counter key ignores case and surrounding spaces', function () {
    LoginLimits::recordFailure(' Victim@Example.com ');
    LoginLimits::recordFailure('victim@example.com');

    expect(LoginLimits::recordFailure('VICTIM@EXAMPLE.COM'))->toBe(3);

    LoginLimits::clear('victim@example.com ');

    expect(LoginLimits::recordFailure('victim@example.com'))->toBe(1);
});
