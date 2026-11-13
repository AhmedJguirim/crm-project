<?php

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Support\Security\KnownDevice;
use App\Support\Security\LoginLimits;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

const KNOWN_DEVICE_FAILED_MESSAGE = 'These credentials do not match our records.';

/**
 * One password step of the panel login with Filament's per-IP counter cleared first, so each attempt looks like it
 * comes from a fresh address and only the per-account and per-device limits can refuse it.
 */
function knownDeviceAttempt(mixed $email, mixed $password): Testable
{
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|'.request()->ip()));

    return Livewire::test(Login::class)
        ->set('data.email', $email)
        ->set('data.password', $password)
        ->call('authenticate');
}

function knownDeviceFail(string $email, int $times): void
{
    foreach (range(1, $times) as $ignored) {
        knownDeviceAttempt($email, 'wrong')->assertHasErrors(['data.email' => KNOWN_DEVICE_FAILED_MESSAGE]);
    }
}

/**
 * Signs in, keeps the cookie the server queued, then signs out again, like a browser that came back later.
 */
function knownDeviceCookieFor(string $email, string $password): string
{
    knownDeviceAttempt($email, $password)->assertHasNoErrors();
    $value = Cookie::queued(KnownDevice::COOKIE)->getValue();
    Filament::auth()->logout();

    return $value;
}

function knownDeviceSendCookie(?string $value): void
{
    (fn () => $this->cookiesForTesting = $value === null ? [] : [KnownDevice::COOKIE => $value])->call(Livewire::getFacadeRoot());
}

function knownDeviceLockedNotification(string $body): Notification
{
    return Notification::make()->title('Too many login attempts')->body($body)->danger();
}

/**
 * @return list<string>
 */
function knownDeviceLogEvents(mixed $log): array
{
    return array_column(securityLogEntries($log), 'event');
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-13 10:00:00', 'UTC'));
    $this->victim = User::factory()->onboardingCompleted()->create(['email' => 'victim@example.com', 'password' => 'correct-horse-1']);
    $this->bystander = User::factory()->onboardingCompleted()->create(['email' => 'bystander@example.com', 'password' => 'correct-horse-2']);
});

test('signing in sets an HttpOnly known-device cookie that holds no email and no user id', function () {
    knownDeviceAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();

    $cookie = Cookie::queued(KnownDevice::COOKIE);
    $parts = explode('|', $cookie->getValue());

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getExpiresTime())->toBe(now()->addMinutes(129600)->getTimestamp())
        ->and($parts)->toHaveCount(3)
        ->and($cookie->getValue())->not->toContain('victim@example.com')
        ->and($parts)->not->toContain((string) $this->victim->id)
        ->and($parts[0])->toBe(sha1('victim@example.com'));

    Filament::auth()->logout();
    knownDeviceAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();

    expect(explode('|', Cookie::queued(KnownDevice::COOKIE)->getValue())[1])->not->toBe($parts[1]);
});

test('the owner browser gets through a lock an attacker caused and the pass is logged without the email', function () {
    $cookie = knownDeviceCookieFor('victim@example.com', 'correct-horse-1');
    knownDeviceFail('victim@example.com', 10);
    $log = fakeSecurityLog();

    knownDeviceSendCookie($cookie);
    knownDeviceAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->victim);
    expect(Cookie::queued(KnownDevice::COOKIE)->getValue())->not->toBe($cookie);
    $entries = collect(securityLogEntries($log))->where('event', 'auth.known_device_login');
    expect($entries)->toHaveCount(1)
        ->and($entries->first()['level'])->toBe('info')
        ->and($entries->first()['context']['user_id'])->toBe($this->victim->id)
        ->and($entries->first()['context']['source'])->toBe('panel_account')
        ->and(json_encode(securityLogEntries($log)))->not->toContain('victim@example.com')->not->toContain($cookie);
});

test('the attacker stays locked after the owner got through, because the account counter is kept', function () {
    $cookie = knownDeviceCookieFor('victim@example.com', 'correct-horse-1');
    knownDeviceFail('victim@example.com', 10);
    knownDeviceSendCookie($cookie);
    knownDeviceAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();
    Filament::auth()->logout();
    knownDeviceSendCookie(null);
    $this->travelTo(Carbon::parse('2026-11-13 10:05:00', 'UTC'));

    knownDeviceAttempt('victim@example.com', 'correct-horse-1')
        ->assertNotified(knownDeviceLockedNotification('Please try again in 10 minutes.'));

    $this->assertGuest();
    expect(LoginLimits::isLocked('victim@example.com'))->toBeTrue();
});

test('the pass works whatever the case of the typed email', function () {
    $cookie = knownDeviceCookieFor('victim@example.com', 'correct-horse-1');
    knownDeviceFail('victim@example.com', 10);
    knownDeviceSendCookie($cookie);

    knownDeviceAttempt('VICTIM@Example.com', 'correct-horse-1')->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->victim);
});

test('a known device with a wrong password is refused like any wrong password', function () {
    $cookie = knownDeviceCookieFor('victim@example.com', 'correct-horse-1');
    knownDeviceFail('victim@example.com', 10);
    knownDeviceSendCookie($cookie);
    $log = fakeSecurityLog();

    knownDeviceAttempt('victim@example.com', 'wrong')->assertHasErrors(['data.email' => KNOWN_DEVICE_FAILED_MESSAGE]);

    $this->assertGuest();
    expect(knownDeviceLogEvents($log))->not->toContain('auth.known_device_login');
});

test('a stolen cookie gets 10 wrong passwords, then waits like everyone else', function () {
    $cookie = knownDeviceCookieFor('victim@example.com', 'correct-horse-1');
    knownDeviceFail('victim@example.com', 10);
    knownDeviceSendCookie($cookie);
    foreach (range(1, 10) as $ignored) {
        knownDeviceAttempt('victim@example.com', 'wrong')->assertHasErrors(['data.email' => KNOWN_DEVICE_FAILED_MESSAGE]);
    }
    $log = fakeSecurityLog();

    knownDeviceAttempt('victim@example.com', 'correct-horse-1')
        ->assertNotified(knownDeviceLockedNotification('Please try again in 15 minutes.'));

    $this->assertGuest();
    expect(knownDeviceLogEvents($log))->not->toContain('auth.known_device_login');
});

test('a cookie issued to another account does not open this one', function () {
    $bystanderCookie = knownDeviceCookieFor('bystander@example.com', 'correct-horse-2');
    knownDeviceFail('victim@example.com', 10);
    knownDeviceSendCookie($bystanderCookie);
    $log = fakeSecurityLog();

    knownDeviceAttempt('victim@example.com', 'correct-horse-1')
        ->assertNotified(knownDeviceLockedNotification('Please try again in 15 minutes.'));

    $this->assertGuest();
    expect(knownDeviceLogEvents($log))->not->toContain('auth.known_device_login');
});

test('a forged or malformed cookie is ignored and never a server error', function (Closure $value) {
    $real = knownDeviceCookieFor('victim@example.com', 'correct-horse-1');
    knownDeviceFail('victim@example.com', 10);
    knownDeviceSendCookie($value($real));
    $log = fakeSecurityLog();

    knownDeviceAttempt('victim@example.com', 'correct-horse-1')
        ->assertNotified(knownDeviceLockedNotification('Please try again in 15 minutes.'));

    $this->assertGuest();
    expect(knownDeviceLogEvents($log))->not->toContain('auth.known_device_login');
})->with([
    'last character changed' => [fn (string $real): string => substr($real, 0, -1).(str_ends_with($real, '0') ? '1' : '0')],
    'device id swapped, old signature' => [fn (string $real): string => implode('|', [explode('|', $real)[0], str_repeat('a', 40), explode('|', $real)[2]])],
    'right email hash, zero signature' => [fn (): string => sha1('victim@example.com').'|'.str_repeat('a', 40).'|'.str_repeat('0', 64)],
    'empty' => [fn (): string => ''],
    'two parts' => [fn (): string => 'a|b'],
    'four parts' => [fn (): string => 'a|b|c|d'],
]);

test('the toggle off makes every browser wait, even one that holds a cookie', function () {
    $cookie = knownDeviceCookieFor('victim@example.com', 'correct-horse-1');
    knownDeviceFail('victim@example.com', 10);
    config(['security.known_device_login.enabled' => false]);
    knownDeviceSendCookie($cookie);

    knownDeviceAttempt('victim@example.com', 'correct-horse-1')
        ->assertNotified(knownDeviceLockedNotification('Please try again in 15 minutes.'));

    $this->assertGuest();
});

test('the toggle off sets no cookie', function () {
    config(['security.known_device_login.enabled' => false]);

    knownDeviceAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();

    expect(Cookie::hasQueued(KnownDevice::COOKIE))->toBeFalse();
});

test('the toggle off counts no device failure, so turning it on again finds a fresh device counter', function () {
    $cookie = knownDeviceCookieFor('victim@example.com', 'correct-horse-1');
    config(['security.known_device_login.enabled' => false]);
    knownDeviceSendCookie($cookie);
    knownDeviceFail('victim@example.com', 10);
    config(['security.known_device_login.enabled' => true]);
    expect(LoginLimits::isLocked('victim@example.com'))->toBeTrue();

    knownDeviceAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();

    $this->assertAuthenticatedAs($this->victim);
});

test('outside a lock a sign-in still clears the account counter and writes no known-device line', function () {
    $cookie = knownDeviceCookieFor('victim@example.com', 'correct-horse-1');
    knownDeviceFail('victim@example.com', 9);
    knownDeviceSendCookie($cookie);
    $log = fakeSecurityLog();

    knownDeviceAttempt('victim@example.com', 'correct-horse-1')->assertHasNoErrors();
    Filament::auth()->logout();
    knownDeviceFail('victim@example.com', 9);

    expect(LoginLimits::isLocked('victim@example.com'))->toBeFalse()
        ->and(knownDeviceLogEvents($log))->not->toContain('auth.known_device_login');
});

test('a wrong password on a browser without a cookie counts for no device', function () {
    knownDeviceFail('victim@example.com', 10);
    $cookie = knownDeviceCookieFor('bystander@example.com', 'correct-horse-2');

    expect(RateLimiter::attempts('login-device:'.sha1(explode('|', $cookie)[1])))->toBe(0);
});
