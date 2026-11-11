<?php

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Support\Security\SecurityLog;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Livewire\Livewire;

/**
 * @param  list<array{event: string, level: string, context: array<string, mixed>}>  $entries
 * @return list<array{event: string, level: string, context: array<string, mixed>}>
 */
function securityEntriesNamed(array $entries, string $event): array
{
    return array_values(array_filter($entries, fn (array $entry): bool => $entry['event'] === $event));
}

test('the security channel is daily, kept 90 days, at info, outside the stack', function () {
    expect(config('logging.channels.security'))->toBe([
        'driver' => 'daily',
        'path' => storage_path('logs/security.log'),
        'level' => 'info',
        'days' => 90,
    ]);
    expect(config('logging.channels.stack.channels'))->not->toContain('security');
});

test('the real channel writes a dated file', function () {
    $dir = sys_get_temp_dir().'/security-log-'.bin2hex(random_bytes(6));
    config(['logging.channels.security.path' => $dir.'/security.log']);
    Log::forgetChannel('security');

    $user = User::factory()->create();

    SecurityLog::info('auth.login', $user);

    $files = glob($dir.'/security-*.log');
    expect($files)->toHaveCount(1);
    expect(File::get($files[0]))->toContain('auth.login')->toContain('"user_id":'.$user->id);

    File::deleteDirectory($dir);
});

test('a panel login is logged with ids and IP', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->onboardingCompleted()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');

    $entries = securityEntriesNamed(securityLogEntries($log), 'auth.login');
    expect($entries)->toHaveCount(1);
    expect($entries[0]['level'])->toBe('info');
    expect($entries[0]['context'])->toBe([
        'guard' => 'web',
        'remember' => false,
        'user_id' => $user->id,
        'organization_id' => null,
        'ip' => '127.0.0.1',
    ]);
});

test('a failed panel login never logs the password or the email', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->create(['email' => 'victim@example.com']);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'victim@example.com', 'password' => 'Secret-Pa55!'])
        ->call('authenticate');

    $entries = securityEntriesNamed(securityLogEntries($log), 'auth.login_failed');
    expect($entries)->toHaveCount(1);
    expect($entries[0]['level'])->toBe('warning');
    expect($entries[0]['context'])->toMatchArray(['user_id' => $user->id, 'guard' => 'web']);

    $dump = json_encode(securityLogEntries($log));
    expect($dump)->not->toContain('Secret-Pa55!')->not->toContain('victim@example.com');
});

test('an unknown email gives a null user id and is not logged', function () {
    $log = fakeSecurityLog();

    Livewire::test(Login::class)
        ->fillForm(['email' => 'nobody@example.com', 'password' => 'whatever1'])
        ->call('authenticate');

    $entries = securityEntriesNamed(securityLogEntries($log), 'auth.login_failed');
    expect($entries)->toHaveCount(1);
    expect($entries[0]['context']['user_id'])->toBeNull();
    expect(json_encode(securityLogEntries($log)))->not->toContain('nobody@example.com');
});

test('the panel login lockout is logged', function () {
    $log = fakeSecurityLog();
    User::factory()->create(['email' => 'victim@example.com']);

    $page = Livewire::test(Login::class)
        ->fillForm(['email' => 'victim@example.com', 'password' => 'wrong-password']);

    foreach (range(1, 6) as $attempt) {
        $page->call('authenticate');
    }

    $entries = securityLogEntries($log);
    expect(collect($entries)->pluck('event')->all())->toBe([
        'auth.login_failed', 'auth.login_failed', 'auth.login_failed', 'auth.login_failed', 'auth.login_failed', 'auth.lockout',
    ]);
    expect($entries[5]['level'])->toBe('warning');
    expect($entries[5]['context'])->toMatchArray(['source' => 'panel', 'user_id' => null]);
});

test('a throttled Fortify login POST is logged with its route and IP', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->create();

    foreach (range(1, 6) as $attempt) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    $response->assertStatus(429);
    $entries = securityEntriesNamed(securityLogEntries($log), 'auth.lockout');
    expect($entries)->toHaveCount(1);
    expect($entries[0]['level'])->toBe('warning');
    expect($entries[0]['context'])->toMatchArray(['source' => 'login.store', 'ip' => '203.0.113.7']);
    expect(json_encode(securityLogEntries($log)))->not->toContain($user->email);
});

test('a throttled password reset request is logged with its route and IP', function () {
    $log = fakeSecurityLog();
    Notification::fake();

    foreach (range(1, 6) as $attempt) {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->post(route('password.email'), ['email' => 'someone@example.com']);
    }

    $response->assertStatus(429);
    $entries = securityEntriesNamed(securityLogEntries($log), 'auth.lockout');
    expect($entries)->toHaveCount(1);
    expect($entries[0]['context'])->toMatchArray(['source' => 'password.email', 'ip' => '203.0.113.7']);
    expect(json_encode(securityLogEntries($log)))->not->toContain('someone@example.com');
});

test('a 429 on any other route logs nothing', function () {
    $log = fakeSecurityLog();
    Route::middleware(['web', 'throttle:1,1'])->get('/test-throttled', fn () => 'ok')->name('test.throttled');

    $this->get('/test-throttled')->assertOk();
    $this->get('/test-throttled')->assertStatus(429);

    expect(securityEntriesNamed(securityLogEntries($log), 'auth.lockout'))->toBe([]);
});

test('a 429 on an unnamed route logs nothing', function () {
    $log = fakeSecurityLog();
    Route::middleware(['web', 'throttle:1,1'])->get('/test-throttled-unnamed', fn () => 'ok');

    $this->get('/test-throttled-unnamed')->assertOk();
    $this->get('/test-throttled-unnamed')->assertStatus(429);

    expect(securityLogEntries($log))->toBe([]);
});

test('logout is logged', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user)->post(Filament::getLogoutUrl());

    $entries = securityEntriesNamed(securityLogEntries($log), 'auth.logout');
    expect($entries)->toHaveCount(1);
    expect($entries[0]['level'])->toBe('info');
    expect($entries[0]['context'])->toMatchArray(['user_id' => $user->id, 'guard' => 'web']);
});

test('registration is logged', function () {
    $log = fakeSecurityLog();

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Ahmed Test',
            'email' => 'ahmed.test@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
            'accept_terms' => true,
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'ahmed.test@example.com')->firstOrFail();
    $entries = securityEntriesNamed(securityLogEntries($log), 'auth.registered');
    expect($entries)->toHaveCount(1);
    expect($entries[0]['level'])->toBe('info');
    expect($entries[0]['context'])->toMatchArray(['user_id' => $user->id]);
    expect(json_encode(securityLogEntries($log)))->not->toContain('ahmed.test@example.com');
});

test('a password reset is logged without the token or the new password', function () {
    $log = fakeSecurityLog();
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    $token = null;
    Notification::assertSentTo($user, QueuedResetPassword::class, function ($notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    $entries = securityEntriesNamed(securityLogEntries($log), 'auth.password_reset');
    expect($entries)->toHaveCount(1);
    expect($entries[0]['level'])->toBe('info');
    expect($entries[0]['context'])->toMatchArray(['user_id' => $user->id]);
    $dump = json_encode(securityLogEntries($log));
    expect($dump)->not->toContain($token)->not->toContain('new-password')->not->toContain($user->email);
});

test('a password change on the settings page is logged and a wrong current password is not', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $this->actingAs($user);

    Livewire::test('pages::settings.password')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $entries = securityLogEntries($log);
    expect($entries)->toHaveCount(1);
    expect($entries[0]['event'])->toBe('account.password_changed');
    expect($entries[0]['level'])->toBe('info');
    expect($entries[0]['context'])->toMatchArray(['user_id' => $user->id]);

    Livewire::test('pages::settings.password')
        ->set('current_password', 'wrong-password')
        ->set('password', 'another-password')
        ->set('password_confirmation', 'another-password')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);

    expect(securityLogEntries($log))->toHaveCount(1);
    expect(json_encode(securityLogEntries($log)))->not->toContain('new-password')->not->toContain('another-password');
});

test('an email change is logged and a name-only change or a case-only change is not', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->create(['email' => 'Ann.Lee@example.com']);
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Ann')
        ->set('email', 'ANN.LEE@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect(securityLogEntries($log))->toBe([]);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Ann')
        ->set('email', 'ann.new@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $entries = securityLogEntries($log);
    expect($entries)->toHaveCount(1);
    expect($entries[0]['event'])->toBe('account.email_changed');
    expect($entries[0]['level'])->toBe('info');
    expect($entries[0]['context'])->toMatchArray(['user_id' => $user->id]);
    expect(json_encode($entries))->not->toContain('ann.lee@example.com')->not->toContain('Ann.Lee@example.com')->not->toContain('ann.new@example.com');
});

test('a failed profile validation logs nothing', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Ann')
        ->set('email', 'not-an-email')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email']);

    expect(securityLogEntries($log))->toBe([]);
});

test('deleting the account is logged before the logout, and a wrong password logs nothing', function () {
    $log = fakeSecurityLog();
    $stranger = User::factory()->create();
    $this->actingAs($stranger);

    Livewire::test('pages::settings.delete-user-form')
        ->set('password', 'wrong-password')
        ->call('deleteUser')
        ->assertHasErrors(['password']);

    expect(securityLogEntries($log))->toBe([]);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors();

    $entries = securityLogEntries($log);
    expect(array_column($entries, 'event'))->toBe(['account.deleted', 'auth.logout']);
    expect($entries[0]['level'])->toBe('warning');
    expect($entries[0]['context'])->toMatchArray(['user_id' => $user->id]);
});

test('two-factor events are logged with the right level', function (string $event, string $level, Closure $dispatch) {
    $log = fakeSecurityLog();
    $user = User::factory()->create();

    $dispatch($user);

    $entries = securityLogEntries($log);
    $last = end($entries);
    expect($last['event'])->toBe($event);
    expect($last['level'])->toBe($level);
    expect($last['context'])->toMatchArray(['user_id' => $user->id]);
})->with([
    'enabled' => ['auth.two_factor_enabled', 'info', fn (User $user) => app(EnableTwoFactorAuthentication::class)($user)],
    'confirmed' => ['auth.two_factor_confirmed', 'info', fn (User $user) => event(new TwoFactorAuthenticationConfirmed($user))],
    'disabled' => ['auth.two_factor_disabled', 'warning', function (User $user) {
        app(EnableTwoFactorAuthentication::class)($user);
        app(DisableTwoFactorAuthentication::class)($user);
    }],
    'failed' => ['auth.two_factor_failed', 'warning', fn (User $user) => event(new TwoFactorAuthenticationFailed($user))],
    'recovery code used' => ['auth.recovery_code_used', 'warning', fn (User $user) => event(new RecoveryCodeReplaced($user, 'abcd1-efgh2'))],
    'recovery codes regenerated' => ['auth.recovery_codes_regenerated', 'info', fn (User $user) => app(GenerateNewRecoveryCodes::class)($user)],
]);

test('a replaced recovery code is never logged', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->create();

    event(new RecoveryCodeReplaced($user, 'abcd1-efgh2'));

    expect(json_encode(securityLogEntries($log)))->not->toContain('abcd1-efgh2');
});

test('the organization id is the current panel tenant and the base keys win over the passed context', function () {
    $log = fakeSecurityLog();
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $organization = $user->personalOrganization();
    $this->actingAs($user);
    Filament::setTenant($organization);

    SecurityLog::info('auth.login', $user, ['user_id' => 999, 'organization_id' => 888, 'ip' => '1.2.3.4', 'guard' => 'web']);

    $entries = securityLogEntries($log);
    expect($entries)->toHaveCount(1);
    expect($entries[0]['context'])->toEqual([
        'guard' => 'web',
        'user_id' => $user->id,
        'organization_id' => $organization->id,
        'ip' => '127.0.0.1',
    ]);
});

test('a broken security log never blocks a login', function () {
    config(['logging.channels.security' => ['driver' => 'single', 'path' => '/dev/null/security.log']]);
    Log::forgetChannel('security');
    $user = User::factory()->onboardingCompleted()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getUrl());

    $this->assertAuthenticatedAs($user);
});
