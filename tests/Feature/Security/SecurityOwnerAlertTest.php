<?php

use App\Models\User;
use App\Notifications\SecurityOwnerAlertNotification;
use App\Support\Security\ExportAlertLimits;
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

function soaExport(mixed $userId, mixed $rows = 100, string $env = 'testing'): string
{
    return soaLine($env, 'INFO', 'data.export_queued', ['list' => 'contacts', 'format' => 'csv', 'rows' => $rows, 'user_id' => $userId, 'organization_id' => 7, 'ip' => '198.51.100.9']);
}

function soaDownload(mixed $userId, string $env = 'testing'): string
{
    return soaLine($env, 'INFO', 'data.export_downloaded', ['list' => 'contacts', 'format' => 'csv', 'file_organization_id' => 7, 'user_id' => $userId, 'organization_id' => 7, 'ip' => '198.51.100.9']);
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
        'security.owner_alerts.exports_per_user_threshold' => 10,
        'security.owner_alerts.exported_rows_per_user_threshold' => 50000,
        'security.owner_alerts.downloads_per_user_threshold' => 20,
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
    expect(collect(soaLines(new SecurityOwnerAlertNotification($log, 20, new ExportAlertLimits(10, 50000, 20))))->contains(fn (string $line): bool => str_starts_with($line, 'More than')))->toBeFalse();
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
    $notification = new SecurityOwnerAlertNotification(SecurityLogDay::read(soaDay()), 20, new ExportAlertLimits(10, 50000, 20));

    expect($notification)->toBeInstanceOf(ShouldQueueAfterCommit::class);
    expect($notification->via(new AnonymousNotifiable))->toBe(['mail']);
});

test('the toggle, address and threshold are read from the environment through the config file', function () {
    $config = require base_path('config/security.php');

    expect($config['owner_alerts'])->toHaveKeys(['enabled', 'email', 'failed_logins_threshold', 'exports_per_user_threshold', 'exported_rows_per_user_threshold', 'downloads_per_user_threshold']);
    expect(File::get(base_path('.env.example')))
        ->toContain("SECURITY_OWNER_ALERTS_ENABLED=true\n")
        ->toContain("SECURITY_OWNER_ALERTS_EMAIL=\n")
        ->toContain("SECURITY_OWNER_ALERTS_FAILED_LOGINS=20\n")
        ->toContain("SECURITY_OWNER_ALERTS_EXPORTS_PER_USER=10\n")
        ->toContain("SECURITY_OWNER_ALERTS_EXPORTED_ROWS_PER_USER=50000\n")
        ->toContain("SECURITY_OWNER_ALERTS_DOWNLOADS_PER_USER=20\n");
});

test('one account above the export count sends the mail with the exact lines', function () {
    soaWrite(str_repeat(soaExport(12), 11).str_repeat(soaDownload(12), 3).str_repeat(soaExport(40), 2));

    $this->artisan('security:alert-owner')
        ->expectsOutput('Security alert for 2026-11-12 queued to the owner address.')
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(SecurityOwnerAlertNotification::class, 1);
    expect(soaLines(soaSent()[0]))->toBe([
        'The security log of '.config('app.name').' for 12 Nov 2026 (UTC) crossed an alert threshold.',
        'Failed sign-ins: 0 (alert above 20).',
        'Failed two-factor codes: 0.',
        'Lockouts: 0.',
        'Exports queued: 13 (1,300 rows); export files downloaded: 3.',
        'Accounts above an export limit (more than 10 exports, 50,000 rows or 20 downloads in the day): #12 (exports 11, rows 1,100, downloads 3).',
        'The full lines are in security-2026-11-12.log in the server\'s storage/logs folder.',
    ]);
});

test('each export limit is strictly above', function (string $content, ?string $listed) {
    soaWrite($content);

    $this->artisan('security:alert-owner')->assertSuccessful();

    if ($listed === null) {
        Notification::assertNothingSent();

        return;
    }

    expect(soaSent())->toHaveCount(1);
    expect(soaLines(soaSent()[0]))->toContain(
        'Accounts above an export limit (more than 10 exports, 50,000 rows or 20 downloads in the day): '.$listed.'.'
    );
})->with([
    '10 exports' => [fn () => str_repeat(soaExport(12, 1), 10), null],
    '11 exports' => [fn () => str_repeat(soaExport(12, 1), 11), '#12 (exports 11, rows 11, downloads 0)'],
    '50,000 rows' => [fn () => str_repeat(soaExport(12, 25000), 2), null],
    '50,001 rows' => [fn () => soaExport(12, 25000).soaExport(12, 25001), '#12 (exports 2, rows 50,001, downloads 0)'],
    '20 downloads' => [fn () => str_repeat(soaDownload(12), 20), null],
    '21 downloads' => [fn () => str_repeat(soaDownload(12), 21), '#12 (exports 0, rows 0, downloads 21)'],
]);

test('exports below the limits on an alert day give the totals line but no accounts line', function () {
    soaWrite(soaFailedLogins(23).soaExport(12, 5));

    $this->artisan('security:alert-owner')->assertSuccessful();

    $lines = soaLines(soaSent()[0]);
    expect($lines)->toContain('Exports queued: 1 (5 rows); export files downloaded: 0.');
    expect(collect($lines)->contains(fn (string $line): bool => str_starts_with($line, 'Accounts above an export limit')))->toBeFalse();
});

test('a sign-in-only alert has no export line', function () {
    soaWrite(soaFailedLogins(23));

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(collect(soaLines(soaSent()[0]))->contains(fn (string $line): bool => str_contains($line, 'xport')))->toBeFalse();
});

test('more than 10 accounts above are cut at 10, ascending', function () {
    $content = '';
    for ($id = 30; $id >= 19; $id--) {
        $content .= str_repeat(soaDownload($id), 21);
    }
    soaWrite($content);

    $this->artisan('security:alert-owner')->assertSuccessful();

    $lines = soaLines(soaSent()[0]);
    $line = collect($lines)->first(fn (string $line): bool => str_starts_with($line, 'Accounts above an export limit'));
    expect($line)->toStartWith('Accounts above an export limit (more than 10 exports, 50,000 rows or 20 downloads in the day): #19 (exports 0, rows 0, downloads 21), #20 ');
    expect($line)->toContain('#28 (exports 0, rows 0, downloads 21) and 2 more.');
    expect($line)->not->toContain('#29');
    expect(SecurityLogDay::read(soaDay())->exportAccountsAbove(new ExportAlertLimits(10, 50000, 20)))->toBe(range(19, 30));
});

test('the limits come from the config and a negative limit is zero', function () {
    config(['security.owner_alerts.exports_per_user_threshold' => -5]);
    soaWrite(soaExport(12, 1));

    expect(ExportAlertLimits::fromConfig())->toEqual(new ExportAlertLimits(0, 50000, 20));

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(soaLines(soaSent()[0]))->toContain(
        'Accounts above an export limit (more than 0 exports, 50,000 rows or 20 downloads in the day): #12 (exports 1, rows 1, downloads 0).'
    );
});

test('the row and download limits come from the config too', function () {
    config(['security.owner_alerts.exported_rows_per_user_threshold' => 99, 'security.owner_alerts.downloads_per_user_threshold' => 2]);

    expect(ExportAlertLimits::fromConfig())->toEqual(new ExportAlertLimits(10, 99, 2));

    soaWrite(soaExport(12, 100));
    $this->artisan('security:alert-owner')->assertSuccessful();
    expect(soaLines(soaSent()[0]))->toContain(
        'Accounts above an export limit (more than 10 exports, 99 rows or 2 downloads in the day): #12 (exports 1, rows 100, downloads 0).'
    );

    soaWrite(str_repeat(soaDownload(13), 3), '2026-11-11');
    $this->artisan('security:alert-owner', ['--date' => '2026-11-11'])->assertSuccessful();
    expect(soaSent())->toHaveCount(2);
});

test('the audit line carries the export counts and no address', function () {
    $this->travelBack();
    $yesterday = now()->subDay()->format('Y-m-d');
    $content = str_replace('2026-11-12', $yesterday, str_repeat(soaExport(12), 11).str_repeat(soaDownload(12), 3));
    soaWrite($content, $yesterday);

    $this->artisan('security:alert-owner')->assertSuccessful();

    $log = File::get($this->soaDir.'/security-'.date('Y-m-d').'.log');
    expect($log)->toContain('"failed_logins":0,"lockouts":0,"exports_queued":11,"export_downloads":3,"export_accounts_above":1');
    expect($log)->not->toContain('ops@example.com');
});

test('the real logger round trip: an export logged today is mailed the next day', function () {
    $this->travelBack();
    $user = User::factory()->create();
    SecurityLog::info('data.export_queued', $user, ['list' => 'contacts', 'format' => 'csv', 'rows' => 60000]);

    $this->travelTo(now()->addDay());

    $this->artisan('security:alert-owner')->assertSuccessful();

    expect(soaLines(soaSent()[0]))->toContain(
        'Accounts above an export limit (more than 10 exports, 50,000 rows or 20 downloads in the day): #'.$user->id.' (exports 1, rows 60,000, downloads 0).'
    );
});

test('an export-only day is mailed once', function () {
    soaWrite(str_repeat(soaExport(12), 11));

    $this->artisan('security:alert-owner')->assertSuccessful();
    $this->artisan('security:alert-owner')
        ->expectsOutput('The security alert for 2026-11-12 was already sent.')
        ->assertSuccessful();

    expect(soaSent())->toHaveCount(1);
});

test('imports never count', function () {
    $imports = str_repeat(soaLine('testing', 'INFO', 'data.import_queued', ['list' => 'contacts', 'rows' => 100000, 'user_id' => 12]), 50)
        .str_repeat(soaLine('testing', 'INFO', 'data.import_finished', ['list' => 'contacts', 'rows' => 100000, 'user_id' => 12]), 50)
        .str_repeat(soaLine('testing', 'INFO', 'data.import_failed_rows_downloaded', ['list' => 'contacts', 'user_id' => 12]), 50);
    soaWrite($imports);

    $log = SecurityLogDay::read(soaDay());
    expect($log->exportsQueued)->toBe(0);
    expect($log->exportedRows)->toBe(0);
    expect($log->exportDownloads)->toBe(0);
    expect($log->exportsByUser)->toBe([]);

    $this->artisan('security:alert-owner')
        ->expectsOutput('No security alert for 2026-11-12: 0 failed sign-ins, 0 lockouts.')
        ->assertSuccessful();

    Notification::assertNothingSent();
});

test('forged user ids in export lines are counted in the totals but never listed', function (mixed $userId) {
    soaWrite(str_repeat(soaExport($userId, 100), 11));

    $log = SecurityLogDay::read(soaDay());

    expect($log->exportsQueued)->toBe(11);
    expect($log->exportedRows)->toBe(1100);
    expect($log->exportsByUser)->toBe([]);
    expect($log->crossesExportLimits(new ExportAlertLimits(10, 50000, 20)))->toBeFalse();
})->with(['string' => '12', 'zero' => 0, 'negative' => -3, 'float' => 1.5, 'null' => null, 'array' => [[12]]]);

test('forged download user ids are counted in the total but never listed', function (mixed $userId) {
    soaWrite(str_repeat(soaDownload($userId), 25));

    $log = SecurityLogDay::read(soaDay());

    expect($log->exportDownloads)->toBe(25);
    expect($log->exportsByUser)->toBe([]);

    $this->artisan('security:alert-owner')->assertSuccessful();

    Notification::assertNothingSent();
})->with(['string' => '12', 'zero' => 0, 'negative' => -3, 'float' => 1.5, 'null' => null]);

test('forged row counts are read as zero', function (mixed $rows) {
    soaWrite(str_repeat(soaExport(12, $rows), 11));

    $log = SecurityLogDay::read(soaDay());

    expect($log->exportsQueued)->toBe(11);
    expect($log->exportedRows)->toBe(0);
    expect($log->exportsByUser)->toBe([12 => ['exports' => 11, 'rows' => 0, 'downloads' => 0]]);
})->with(['string' => '50000', 'negative' => -1, 'float' => 2.5, 'null' => null, 'array' => [[5]]]);

test('export lines of another environment never count', function () {
    soaWrite(str_repeat(soaExport(12, 100, 'production'), 11).str_repeat(soaDownload(12, 'production'), 25));

    $log = SecurityLogDay::read(soaDay());
    expect($log->exportsQueued)->toBe(0);
    expect($log->exportDownloads)->toBe(0);

    $this->artisan('security:alert-owner')->assertSuccessful();

    Notification::assertNothingSent();
});

test('the mail never carries list names, file organizations or addresses of export lines', function () {
    $line = soaLine('testing', 'INFO', 'data.export_queued', [
        'list' => 'segment-secret-list', 'format' => 'csv', 'rows' => 100, 'segment_id' => 99, 'user_id' => 12,
        'organization_id' => 7, 'ip' => '198.51.100.9', 'file_organization_id' => 4242,
    ]);
    soaWrite(str_repeat($line, 11));

    $this->artisan('security:alert-owner')->assertSuccessful();

    $text = implode("\n", soaLines(soaSent()[0]));
    expect($text)->toContain('#12 (exports 11, rows 1,100, downloads 0)');
    expect($text)->not->toContain('segment-secret-list');
    expect($text)->not->toContain('99');
    expect($text)->not->toContain('198.51.100.9');
    expect($text)->not->toContain('4242');
});

test('the per-account export map is capped, the totals stay exact and the mail says so', function () {
    $content = '';
    for ($i = 1; $i <= SecurityLogDay::MAX_TRACKED + 1; $i++) {
        $content .= soaDownload($i);
    }
    soaWrite($content);

    $log = SecurityLogDay::read(soaDay());

    expect($log->exportDownloads)->toBe(SecurityLogDay::MAX_TRACKED + 1);
    expect($log->exportsByUser)->toHaveCount(SecurityLogDay::MAX_TRACKED);
    expect($log->trackingCapped)->toBeTrue();
});

test('an account already tracked still counts after the export map is full', function () {
    $content = '';
    for ($i = 1; $i <= SecurityLogDay::MAX_TRACKED + 1; $i++) {
        $content .= soaExport($i, 1);
    }
    soaWrite($content.str_repeat(soaDownload(1), 3).soaExport(1, 4));

    $log = SecurityLogDay::read(soaDay());

    expect($log->exportsQueued)->toBe(SecurityLogDay::MAX_TRACKED + 2);
    expect($log->exportsByUser[1])->toBe(['exports' => 2, 'rows' => 5, 'downloads' => 3]);
    expect($log->exportsByUser)->not->toHaveKey(SecurityLogDay::MAX_TRACKED + 1);
});

test('export-only counts do not change the sign-in decision', function () {
    soaWrite(str_repeat(soaExport(12, 1), 50).str_repeat(soaDownload(12), 50));

    $log = SecurityLogDay::read(soaDay());

    expect($log->crosses(20))->toBeFalse();
    expect($log->crossesExportLimits(new ExportAlertLimits(10, 50000, 20)))->toBeTrue();
});

test('two export lines with the largest integer as rows do not break the day and keep the sign-in alert', function () {
    soaWrite(soaFailedLogins(23).str_repeat(soaExport(12, PHP_INT_MAX), 2));

    $log = SecurityLogDay::read(soaDay());
    expect($log->exportedRows)->toBe(PHP_INT_MAX);
    expect($log->exportsByUser[12]['rows'])->toBe(PHP_INT_MAX);
    expect($log->failedLogins)->toBe(23);

    $this->artisan('security:alert-owner')
        ->expectsOutput('Security alert for 2026-11-12 queued to the owner address.')
        ->assertSuccessful();

    Notification::assertSentOnDemandTimes(SecurityOwnerAlertNotification::class, 1);
    expect(soaLines(soaSent()[0]))->toContain('Failed sign-ins: 23 (alert above 20).');
});

test('two export lines with the largest integer as rows alone still list the account', function () {
    soaWrite(str_repeat(soaExport(12, PHP_INT_MAX), 2));

    $this->artisan('security:alert-owner')->assertSuccessful();

    Notification::assertSentOnDemandTimes(SecurityOwnerAlertNotification::class, 1);
    $text = implode("\n", soaLines(soaSent()[0]));
    expect($text)->toContain('Exports queued: 2 ('.number_format(PHP_INT_MAX).' rows)');
    expect($text)->toContain('#12 (exports 2, rows '.number_format(PHP_INT_MAX).', downloads 0)');
});
