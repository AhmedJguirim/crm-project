<?php

use App\Notifications\SecurityOwnerAlertNotification;
use App\Support\Security\SecurityLog;
use App\Support\Security\SecurityLogDay;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * @param  array<string, mixed>  $context
 */
function soaLine(string $env, string $level, string $event, array $context = []): string
{
    return "[2026-11-12 08:00:00] {$env}.{$level}: {$event} ".json_encode($context)." \n";
}

function soaFailedLogins(int $count, string $ip = '203.0.113.7', string $env = 'testing'): string
{
    return str_repeat(soaLine($env, 'WARNING', 'auth.login_failed', ['user_id' => null, 'organization_id' => null, 'ip' => $ip]), $count);
}

function soaLockout(?int $userId, string $source = 'panel_account', string $env = 'testing'): string
{
    return soaLine($env, 'WARNING', 'auth.lockout', ['source' => $source, 'user_id' => $userId, 'organization_id' => null, 'ip' => '203.0.113.7']);
}

function soaWrite(string $content, string $date = '2026-11-12'): void
{
    File::put(test()->soaDir.'/security-'.$date.'.log', $content);
}

function soaDay(string $date = '2026-11-12'): CarbonImmutable
{
    return CarbonImmutable::createFromFormat('!Y-m-d', $date);
}

/**
 * @return list<SecurityOwnerAlertNotification>
 */
function soaSent(): array
{
    return collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])
        ->flatten(2)
        ->pluck('notification')
        ->filter(fn ($notification): bool => $notification instanceof SecurityOwnerAlertNotification)
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function soaLines(SecurityOwnerAlertNotification $notification): array
{
    return $notification->toMail(new AnonymousNotifiable)->introLines;
}

beforeEach(function () {
    $this->soaDir = sys_get_temp_dir().'/security-alert-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->soaDir);

    config([
        'logging.channels.security' => ['driver' => 'daily', 'path' => $this->soaDir.'/security.log', 'level' => 'info', 'days' => 90],
        'security.owner_alerts.enabled' => true,
        'security.owner_alerts.email' => 'ops@example.com',
        'security.owner_alerts.failed_logins_threshold' => 20,
    ]);
    Log::forgetChannel('security');

    $this->travelTo(CarbonImmutable::parse('2026-11-13 00:20:00', 'UTC'));
    Notification::fake();
});

afterEach(function () {
    Log::forgetChannel('security');
    File::deleteDirectory($this->soaDir);
});

test('many failed sign-ins send one mail to the owner address with the exact lines', function () {
    soaWrite(
        soaFailedLogins(15, '203.0.113.7').soaFailedLogins(5, '198.51.100.2').soaFailedLogins(3, '192.0.2.1')
        .str_repeat(soaLine('testing', 'WARNING', 'auth.two_factor_failed', ['ip' => '203.0.113.7']), 2)
    );

    $this->artisan('security:alert-owner')
        ->expectsOutput('Security alert for 2026-11-12 queued to the owner address.')
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(SecurityOwnerAlertNotification::class, 1);
    Notification::assertSentOnDemand(SecurityOwnerAlertNotification::class, fn ($n, $c, $notifiable): bool => $notifiable->routes['mail'] === 'ops@example.com');

    $mail = soaSent()[0]->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe('Security alert: 12 Nov 2026 (UTC)');
    expect($mail->introLines)->toBe([
        'The security log of '.config('app.name').' for 12 Nov 2026 (UTC) crossed an alert threshold.',
        'Failed sign-ins: 23 (alert above 20).',
        'Failed two-factor codes: 2.',
        'Lockouts: 0.',
        'Most failed sign-ins by IP address: 203.0.113.7 (15), 198.51.100.2 (5), 192.0.2.1 (3).',
        'The full lines are in security-2026-11-12.log in the server\'s storage/logs folder.',
    ]);
    expect($mail->actionText)->toBeNull();
});

test('one lockout is enough and lists the sources and the locked accounts', function () {
    soaWrite(soaFailedLogins(1).soaLockout(9).soaLockout(4).soaLockout(null, 'panel'));

    $this->artisan('security:alert-owner')->assertSuccessful();

    $lines = soaLines(soaSent()[0]);
    expect($lines)->toContain('Lockouts: 3 (one account: 2, one IP on the sign-in page: 1).');
    expect($lines)->toContain('Locked accounts: #4, #9.');
});

test('every lockout source has its own label, in a fixed order', function () {
    soaWrite(
        soaLockout(null, 'password.update').soaLockout(null, 'password.email').soaLockout(null, 'whatever')
        .soaLockout(null, 'panel').soaLockout(null, 'panel_account')
    );

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(soaLines(soaSent()[0]))->toContain('Lockouts: 5 (one account: 1, one IP on the sign-in page: 1, password reset requests: 1, password reset form: 1, other: 1).');
});

test('the locked accounts list is cut at 10, ascending, each account once', function () {
    $lockouts = '';
    foreach (array_reverse(range(1, 13)) as $id) {
        $lockouts .= soaLockout($id);
    }
    soaWrite($lockouts.soaLockout(3));

    $this->artisan('security:alert-owner')->assertSuccessful();

    $lines = soaLines(soaSent()[0]);
    expect($lines)->toContain('Locked accounts: #1, #2, #3, #4, #5, #6, #7, #8, #9, #10 and 3 more.');
    expect($lines)->toContain('Lockouts: 14 (one account: 14).');
});

test('at or below the threshold with no lockout sends nothing', function (int $count) {
    soaWrite(soaFailedLogins($count));

    $this->artisan('security:alert-owner')
        ->expectsOutput("No security alert for 2026-11-12: {$count} failed sign-ins, 0 lockouts.")
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect(Cache::has('security-owner-alert:2026-11-12'))->toBeFalse();
})->with([0, 1, 20]);

test('21 failed sign-ins is above the threshold of 20', function () {
    soaWrite(soaFailedLogins(21));

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(soaSent())->toHaveCount(1);
});

test('the threshold comes from the config', function () {
    config(['security.owner_alerts.failed_logins_threshold' => 2]);
    soaWrite(soaFailedLogins(3));

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(soaLines(soaSent()[0]))->toContain('Failed sign-ins: 3 (alert above 2).');
});

test('a negative threshold is read as zero', function () {
    config(['security.owner_alerts.failed_logins_threshold' => -5]);
    soaWrite(soaFailedLogins(1));

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(soaLines(soaSent()[0]))->toContain('Failed sign-ins: 1 (alert above 0).');
});

test('a missing file is a quiet day', function () {
    $this->artisan('security:alert-owner')
        ->expectsOutput('No security alert for 2026-11-12: 0 failed sign-ins, 0 lockouts.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

test('turned off, it reads nothing and sends nothing', function () {
    config(['security.owner_alerts.enabled' => false]);
    soaWrite(soaFailedLogins(50));

    $this->artisan('security:alert-owner')
        ->expectsOutput('Owner security alerts are turned off (SECURITY_OWNER_ALERTS_ENABLED=false).')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect(Cache::has('security-owner-alert:2026-11-12'))->toBeFalse();
});

test('no usable address fails before anything is read', function (mixed $email) {
    config(['security.owner_alerts.email' => $email]);
    soaWrite(soaFailedLogins(50));

    $this->artisan('security:alert-owner')
        ->expectsOutput('SECURITY_OWNER_ALERTS_EMAIL is not a valid email address; no alert sent.')
        ->assertFailed();

    Notification::assertNothingSent();
    expect(Cache::has('security-owner-alert:2026-11-12'))->toBeFalse();
})->with([
    'null' => null,
    'empty' => '',
    'no at sign' => 'not-an-email',
    'a space' => 'a@b.c d@e.f',
]);

test('an address that is an array is not an address', function () {
    config(['security.owner_alerts.email' => ['ops@example.com']]);
    soaWrite(soaFailedLogins(50));

    $this->artisan('security:alert-owner')->assertFailed();

    Notification::assertNothingSent();
});

test('the toggle is checked before the address', function () {
    config(['security.owner_alerts.enabled' => false, 'security.owner_alerts.email' => null]);

    $this->artisan('security:alert-owner')->assertSuccessful();
});

test('--date reports on a past day', function () {
    soaWrite(soaLockout(7), '2026-11-10');

    $this->artisan('security:alert-owner', ['--date' => '2026-11-10'])
        ->expectsOutput('Security alert for 2026-11-10 queued to the owner address.')
        ->assertSuccessful();

    $mail = soaSent()[0]->toMail(new AnonymousNotifiable);
    expect($mail->subject)->toBe('Security alert: 10 Nov 2026 (UTC)');
    expect(last($mail->introLines))->toBe('The full lines are in security-2026-11-10.log in the server\'s storage/logs folder.');
});

test('a forged --date is refused before any read', function (string $date) {
    soaWrite(soaLockout(7));
    soaWrite(soaLockout(7), '2026-11-14');

    $this->artisan('security:alert-owner', ['--date' => $date])
        ->expectsOutput('The --date option must be a past day written YYYY-MM-DD.')
        ->assertFailed();

    Notification::assertNothingSent();
    expect(Cache::has('security-owner-alert:2026-11-12'))->toBeFalse();
})->with([
    'traversal' => '../../../etc/passwd',
    'traversal after a date' => '2026-11-12/../x',
    'impossible day' => '2026-02-30',
    'impossible month' => '2026-13-45',
    'today' => '2026-11-13',
    'tomorrow' => '2026-11-14',
    'wrong order' => '12-11-2026',
    'trailing newline' => "2026-11-12\n",
    'empty' => '',
    'padded' => ' 2026-11-12',
]);

test('the real daily logger round trip: a lockout logged today is mailed the next day', function () {
    $this->travelBack();
    SecurityLog::warning('auth.lockout', null, ['source' => 'panel_account']);

    $this->travelTo(now()->addDay());

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(soaLines(soaSent()[0]))->toContain('Lockouts: 1 (one account: 1).');
});

test('a sent alert leaves one audit line without the address', function () {
    $this->travelBack();
    $today = date('Y-m-d');
    soaWrite(soaFailedLogins(23).soaLockout(4).soaLockout(null, 'panel'), now()->subDay()->format('Y-m-d'));

    $this->artisan('security:alert-owner')->assertSuccessful();

    $log = File::get($this->soaDir.'/security-'.$today.'.log');
    expect($log)->toContain('security.owner_alert_sent');
    expect($log)->toContain('"date":"'.now()->subDay()->format('Y-m-d').'","failed_logins":23,"lockouts":2');
    expect($log)->not->toContain('ops@example.com');
});

test('a quiet day writes no audit line', function () {
    $this->travelBack();

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(glob($this->soaDir.'/security-*.log'))->toBe([]);
});

test('the schedule runs it daily at 00:20 without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'security:alert-owner'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('20 0 * * *');
    expect($event->withoutOverlapping)->toBeTrue();
});

test('lines from another environment never count', function () {
    soaWrite(soaFailedLogins(50, '203.0.113.7', 'production').soaLockout(1, 'panel', 'local').soaLockout(2, 'panel', 'production'));

    $this->artisan('security:alert-owner')
        ->expectsOutput('No security alert for 2026-11-12: 0 failed sign-ins, 0 lockouts.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

test('a configured channel name replaces the environment name', function () {
    config(['logging.channels.security.name' => 'custom']);
    soaWrite(soaFailedLogins(30, '203.0.113.7', 'testing').soaFailedLogins(3, '203.0.113.7', 'custom'));

    $this->artisan('security:alert-owner')
        ->expectsOutput('No security alert for 2026-11-12: 3 failed sign-ins, 0 lockouts.')
        ->assertSuccessful();
});

test('a forged address in the log never reaches the mail', function () {
    soaWrite(
        soaFailedLogins(25, '[click](https://evil.example)').soaFailedLogins(1, '<script>alert(1)</script>')
        .soaFailedLogins(1, '999.1.1.1').soaFailedLogins(1, '')
    );

    $this->artisan('security:alert-owner')->assertSuccessful();

    $notification = soaSent()[0];
    expect(soaLines($notification))->toContain('Most failed sign-ins by IP address: unknown (28).');

    $html = (string) $notification->toMail(new AnonymousNotifiable)->render();
    expect($html)->not->toContain('evil.example')->not->toContain('<script>');
});

test('a log line without an ip or with a non-string ip counts as unknown', function () {
    soaWrite(
        soaLine('testing', 'WARNING', 'auth.login_failed', ['ip' => null]).soaLine('testing', 'WARNING', 'auth.login_failed', ['ip' => ['a']])
        .soaLine('testing', 'WARNING', 'auth.login_failed', ['user_id' => 4])
    );

    $log = SecurityLogDay::read(soaDay());

    expect($log->failedLogins)->toBe(3);
    expect($log->failedLoginsByIp)->toBe(['unknown' => 3]);
});

test('addresses are ranked by count then address, five at most, IPv6 included', function () {
    soaWrite(
        soaFailedLogins(1, '10.0.0.9').soaFailedLogins(1, '10.0.0.2').soaFailedLogins(1, '2001:db8::1')
        .soaFailedLogins(1, '10.0.0.5').soaFailedLogins(1, '10.0.0.7').soaFailedLogins(2, '198.51.100.2')
        .soaFailedLogins(1, '10.0.0.1').soaFailedLogins(20, '203.0.113.7')
    );

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(soaLines(soaSent()[0]))->toContain('Most failed sign-ins by IP address: 203.0.113.7 (20), 198.51.100.2 (2), 10.0.0.1 (1), 10.0.0.2 (1), 10.0.0.5 (1).');
});

test('forged user ids in lockout lines are not listed but the lockout still counts', function () {
    $lockouts = '';
    foreach (['7', -1, 0, 1.5, true, [3], null] as $id) {
        $lockouts .= soaLine('testing', 'WARNING', 'auth.lockout', ['source' => 'panel_account', 'user_id' => $id]);
    }
    soaWrite($lockouts);

    $log = SecurityLogDay::read(soaDay());

    expect($log->lockouts())->toBe(7);
    expect($log->lockedUserIds)->toBe([]);
});

test('a lockout source that is not a known one is counted as other', function () {
    soaWrite(soaLine('testing', 'WARNING', 'auth.lockout', ['source' => '<b>x</b>', 'user_id' => 3]).soaLine('testing', 'WARNING', 'auth.lockout', ['source' => ['a']]));

    $log = SecurityLogDay::read(soaDay());

    expect($log->lockoutsBySource)->toBe(['other' => 2]);
});

test('running twice the same day sends one mail', function () {
    soaWrite(soaLockout(4));

    $this->artisan('security:alert-owner')->assertSuccessful();
    $this->artisan('security:alert-owner')
        ->expectsOutput('The security alert for 2026-11-12 was already sent.')
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(SecurityOwnerAlertNotification::class, 1);
});

test('a different day is a different alert', function () {
    soaWrite(soaLockout(4));
    soaWrite(soaLockout(4), '2026-11-11');

    $this->artisan('security:alert-owner')->assertSuccessful();
    $this->artisan('security:alert-owner', ['--date' => '2026-11-11'])->assertSuccessful();

    Notification::assertSentOnDemandTimes(SecurityOwnerAlertNotification::class, 2);
});

test('a notification that cannot be queued gives the day back, is reported and fails', function () {
    Exceptions::fake();
    soaWrite(soaLockout(4));
    Notification::swap(Mockery::mock(ChannelManager::class, fn ($mock) => $mock
        ->shouldReceive('send')->once()->andThrow(new RuntimeException('queue down'))
        ->getMock()));

    $this->artisan('security:alert-owner')
        ->expectsOutput('The security alert for 2026-11-12 could not be queued.')
        ->assertFailed();

    Exceptions::assertReported(RuntimeException::class);
    expect(Cache::has('security-owner-alert:2026-11-12'))->toBeFalse();
    expect(glob($this->soaDir.'/security-*.log'))->toBe([$this->soaDir.'/security-2026-11-12.log']);
});

test('garbage, empty and unparsable-context lines are skipped, not fatal', function () {
    soaWrite(
        "garbage\n\n[2026-11-12 01:00:00] testing.WARNING: auth.lockout {not json}\n"
        .soaLockout(4)."[2026-11-12 08:00:00] testing.WARNING: AUTH.lockout {\"a\":1} \n"
        ."[2026-11-12 08:00:00] testing.WARNING: auth.lockout[] {\"a\":1} \n"
    );

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(soaLines(soaSent()[0]))->toContain('Lockouts: 2 (one account: 1, other: 1).');
});

test('other events are ignored', function () {
    soaWrite(
        soaLine('testing', 'INFO', 'auth.login', ['ip' => '203.0.113.7']).soaLine('testing', 'INFO', 'password.update')
        .soaLine('testing', 'INFO', 'security.owner_alert_sent', ['lockouts' => 3])
    );

    $log = SecurityLogDay::read(soaDay());

    expect($log->failedLogins)->toBe(0)->and($log->failedTwoFactor)->toBe(0)->and($log->lockouts())->toBe(0);
    expect($log->crosses(0))->toBeFalse();
});

test('two-factor failures alone do not trigger an alert', function () {
    soaWrite(str_repeat(soaLine('testing', 'WARNING', 'auth.two_factor_failed', ['ip' => '203.0.113.7']), 50));

    $this->artisan('security:alert-owner')
        ->expectsOutput('No security alert for 2026-11-12: 0 failed sign-ins, 0 lockouts.')
        ->assertSuccessful();
});

test('a flood is streamed with the tracked addresses and accounts capped and the totals exact', function () {
    $content = '';
    for ($i = 0; $i < SecurityLogDay::MAX_TRACKED + 5; $i++) {
        $ip = '10.'.intdiv($i, 65536).'.'.intdiv($i % 65536, 256).'.'.($i % 256);
        $content .= soaLine('testing', 'WARNING', 'auth.login_failed', ['ip' => $ip]);
    }
    soaWrite($content.str_repeat(soaLine('testing', 'WARNING', 'auth.login_failed', ['ip' => '10.0.0.0']), 3));

    $log = SecurityLogDay::read(soaDay());

    expect($log->failedLogins)->toBe(SecurityLogDay::MAX_TRACKED + 8);
    expect($log->failedLoginsByIp)->toHaveCount(SecurityLogDay::MAX_TRACKED);
    expect($log->failedLoginsByIp['10.0.0.0'])->toBe(4);
    expect($log->trackingCapped)->toBeTrue();
});

test('the locked accounts are capped too and the mail says so', function () {
    $content = '';
    for ($i = 1; $i <= SecurityLogDay::MAX_TRACKED + 5; $i++) {
        $content .= soaLockout($i);
    }
    soaWrite($content);

    $log = SecurityLogDay::read(soaDay());

    expect($log->lockouts())->toBe(SecurityLogDay::MAX_TRACKED + 5);
    expect($log->lockedUserIds)->toHaveCount(SecurityLogDay::MAX_TRACKED);
    expect($log->trackingCapped)->toBeTrue();

    $this->artisan('security:alert-owner')->assertSuccessful();

    $lines = soaLines(soaSent()[0]);
    expect($lines)->toContain('More than 10,000 addresses or accounts showed up; the lists above cover the first 10,000 seen.');
    expect($lines)->toContain('Locked accounts: #1, #2, #3, #4, #5, #6, #7, #8, #9, #10 and 9990 more.');
});

test('below the caps the mail has no cap line', function () {
    soaWrite(soaLockout(4));

    $log = SecurityLogDay::read(soaDay());

    expect($log->trackingCapped)->toBeFalse();
    expect(collect(soaLines(new SecurityOwnerAlertNotification($log, 20)))->contains(fn (string $line): bool => str_starts_with($line, 'More than')))->toBeFalse();
});

test('a log channel that is not a daily file is refused', function () {
    fakeSecurityLog();
    soaWrite(soaLockout(4));

    $this->artisan('security:alert-owner')
        ->expectsOutput('The security log channel is not a daily file, so there is nothing to read.')
        ->assertFailed();

    Notification::assertNothingSent();
});

test('the alert is a queued, mail-only notification', function () {
    $notification = new SecurityOwnerAlertNotification(SecurityLogDay::read(soaDay()), 20);

    expect($notification)->toBeInstanceOf(ShouldQueueAfterCommit::class);
    expect($notification->via(new AnonymousNotifiable))->toBe(['mail']);
});

test('the toggle, address and threshold are read from the environment through the config file', function () {
    $config = require base_path('config/security.php');

    expect($config['owner_alerts'])->toHaveKeys(['enabled', 'email', 'failed_logins_threshold']);
    expect(File::get(base_path('.env.example')))
        ->toContain("SECURITY_OWNER_ALERTS_ENABLED=true\n")
        ->toContain("SECURITY_OWNER_ALERTS_EMAIL=\n")
        ->toContain("SECURITY_OWNER_ALERTS_FAILED_LOGINS=20\n");
});
