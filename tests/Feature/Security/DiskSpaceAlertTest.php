<?php

use App\Enums\DiskAlertLevel;
use App\Notifications\DiskSpaceAlertNotification;
use App\Support\Security\DiskSpace;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

const DSA_TOTAL = 37580963840.0;

class DsaBrokenCacheStore extends ArrayStore
{
    public int $forgotten = 0;

    public function get($key)
    {
        throw new RuntimeException('cache down');
    }

    public function add($key, $value, $seconds)
    {
        throw new RuntimeException('cache down');
    }

    public function forget($key)
    {
        $this->forgotten++;

        return true;
    }
}

function dsaFree(int|float $bytes): void
{
    app()->forgetInstance(DiskSpace::class);
    test()->mock(DiskSpace::class, fn ($mock) => $mock
        ->shouldReceive('read')->with(storage_path())->andReturn(['free' => (float) $bytes, 'total' => DSA_TOTAL]));
}

/**
 * @return list<DiskSpaceAlertNotification>
 */
function dsaSent(): array
{
    return collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])
        ->flatten(2)
        ->pluck('notification')
        ->filter(fn ($notification): bool => $notification instanceof DiskSpaceAlertNotification)
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function dsaLines(DiskSpaceAlertNotification $notification): array
{
    return $notification->toMail(new AnonymousNotifiable)->introLines;
}

beforeEach(function () {
    $this->dsaDir = sys_get_temp_dir().'/disk-alert-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->dsaDir);

    config([
        'app.name' => 'Acme CRM',
        'logging.channels.security' => ['driver' => 'daily', 'path' => $this->dsaDir.'/security.log', 'level' => 'info', 'days' => 90],
        'security.disk_alert.enabled' => true,
        'security.disk_alert.warn_free_gb' => 8,
        'security.disk_alert.critical_free_gb' => 4,
        'security.owner_alerts.email' => 'ops@example.com',
    ]);
    Log::forgetChannel('security');

    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:40:00', 'UTC'));
    Notification::fake();
});

afterEach(function () {
    Log::forgetChannel('security');
    File::deleteDirectory($this->dsaDir);
});

test('plenty of space sends nothing', function () {
    dsaFree(21474836480);

    $this->artisan('security:check-disk')
        ->expectsOutput('Disk space OK: 20.0 GB free of 35.0 GB.')
        ->assertSuccessful();

    expect(dsaSent())->toBe([]);
});

test('the level depends on the free space, strictly below each threshold', function (int $bytes, ?DiskAlertLevel $level, string $output) {
    dsaFree($bytes);

    $this->artisan('security:check-disk')->expectsOutput($output)->assertSuccessful();

    $sent = dsaSent();
    expect($sent)->toHaveCount($level === null ? 0 : 1);

    if ($level !== null) {
        expect($sent[0]->level)->toBe($level);
    }
})->with([
    'exactly 8 GB is fine' => [8589934592, null, 'Disk space OK: 8.0 GB free of 35.0 GB.'],
    '7.5 GB is a warning' => [8053063680, DiskAlertLevel::Warning, 'Warning disk alert sent to the owner address: 7.5 GB free.'],
    'exactly 4 GB is a warning' => [4294967296, DiskAlertLevel::Warning, 'Warning disk alert sent to the owner address: 4.0 GB free.'],
    '3 GB is critical' => [3221225472, DiskAlertLevel::Critical, 'Critical disk alert sent to the owner address: 3.0 GB free.'],
    'one byte under 4 GB is critical' => [4294967295, DiskAlertLevel::Critical, 'Critical disk alert sent to the owner address: 4.0 GB free.'],
    'zero bytes is critical' => [0, DiskAlertLevel::Critical, 'Critical disk alert sent to the owner address: 0.0 GB free.'],
]);

test('the warning mail has the exact text and goes to the owner address only', function () {
    dsaFree(8053063680);

    $this->artisan('security:check-disk')->assertSuccessful();

    Notification::assertSentOnDemand(DiskSpaceAlertNotification::class, function ($notification, $channels, $notifiable): bool {
        expect($notifiable->routes['mail'])->toBe('ops@example.com');
        expect($channels)->toBe(['mail']);
        expect($notification->toMail($notifiable)->subject)->toBe('Disk space warning: 7.5 GB free on Acme CRM');
        expect(dsaLines($notification))->toBe([
            'The disk that holds the storage folder of Acme CRM has 7.5 GB free of 35.0 GB (79% used).',
            'This is below the warning level of 8.0 GB free.',
            'To free space: run php artisan uploads:prune and php artisan exports:prune, remove old files in storage/logs, and check the size of the database.',
            'You get at most one alert of each level a day.',
        ]);

        return true;
    });
    Notification::assertSentOnDemandTimes(DiskSpaceAlertNotification::class, 1);
});

test('the critical mail has the exact text', function () {
    dsaFree(3221225472);

    $this->artisan('security:check-disk')->assertSuccessful();

    $notification = dsaSent()[0];
    expect($notification->toMail(new AnonymousNotifiable)->subject)->toBe('Disk space critical: 3.0 GB free on Acme CRM');
    expect(dsaLines($notification)[0])->toBe('The disk that holds the storage folder of Acme CRM has 3.0 GB free of 35.0 GB (91% used).');
    expect(dsaLines($notification)[1])->toBe('This is below the critical level of 4.0 GB free. When the disk is full, sign-ins, uploads, logs and queued jobs fail for every user.');
});

test('the mail is sent now, not queued', function () {
    expect(new DiskSpaceAlertNotification(DiskAlertLevel::Warning, 1.0, 2.0, 8.0))->not->toBeInstanceOf(ShouldQueue::class);
    expect(class_implements(DiskSpaceAlertNotification::class))->not->toHaveKey(ShouldQueue::class);
    expect(class_uses(DiskSpaceAlertNotification::class))->not->toContain(Queueable::class);
});

test('running every hour sends one mail per level per day', function () {
    dsaFree(8053063680);

    $this->artisan('security:check-disk')->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-11-16 11:40:00', 'UTC'));
    $this->artisan('security:check-disk')
        ->expectsOutput('The warning disk alert for 2026-11-16 was already sent.')
        ->assertSuccessful();

    expect(dsaSent())->toHaveCount(1);
});

test('a critical after a warning the same day is sent, a warning after a critical is not', function () {
    dsaFree(8053063680);
    $this->artisan('security:check-disk')->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-11-16 11:40:00', 'UTC'));
    dsaFree(3221225472);
    $this->artisan('security:check-disk')->assertSuccessful();
    expect(dsaSent())->toHaveCount(2);
    expect(dsaSent()[1]->level)->toBe(DiskAlertLevel::Critical);

    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:40:00', 'UTC'));
    dsaFree(8053063680);
    $this->artisan('security:check-disk')
        ->expectsOutput('The critical disk alert for 2026-11-16 was already sent; no warning sent.')
        ->assertSuccessful();

    expect(dsaSent())->toHaveCount(2);
});

test('a second critical the same day is not sent', function () {
    dsaFree(3221225472);
    $this->artisan('security:check-disk')->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-11-16 11:40:00', 'UTC'));
    $this->artisan('security:check-disk')
        ->expectsOutput('The critical disk alert for 2026-11-16 was already sent.')
        ->assertSuccessful();

    expect(dsaSent())->toHaveCount(1);
});

test('the next day is a new alert', function () {
    dsaFree(8053063680);
    $this->artisan('security:check-disk')->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-11-17 00:40:00', 'UTC'));
    $this->artisan('security:check-disk')->assertSuccessful();

    expect(dsaSent())->toHaveCount(2);
});

test('turned off, nothing is read or sent', function () {
    config(['security.disk_alert.enabled' => false]);
    $this->mock(DiskSpace::class, fn ($mock) => $mock->shouldNotReceive('read'));

    $this->artisan('security:check-disk')
        ->expectsOutput('Disk space alerts are turned off (SECURITY_DISK_ALERT_ENABLED=false).')
        ->assertSuccessful();

    expect(dsaSent())->toBe([]);
});

test('turning the daily owner alerts off does not turn the disk alert off', function () {
    config(['security.owner_alerts.enabled' => false]);
    dsaFree(3221225472);

    $this->artisan('security:check-disk')->assertSuccessful();

    expect(dsaSent())->toHaveCount(1);
});

test('a critical level above the warning level is refused before reading', function () {
    config(['security.disk_alert.critical_free_gb' => 10, 'security.disk_alert.warn_free_gb' => 8]);
    $this->mock(DiskSpace::class, fn ($mock) => $mock->shouldNotReceive('read'));

    $this->artisan('security:check-disk')
        ->expectsOutput('SECURITY_DISK_CRITICAL_FREE_GB must not be higher than SECURITY_DISK_WARN_FREE_GB; no disk check done.')
        ->assertFailed();

    expect(dsaSent())->toBe([]);
});

test('equal levels are allowed and critical wins', function () {
    config(['security.disk_alert.critical_free_gb' => 8, 'security.disk_alert.warn_free_gb' => 8]);
    dsaFree(8053063680);

    $this->artisan('security:check-disk')->assertSuccessful();
    expect(dsaSent()[0]->level)->toBe(DiskAlertLevel::Critical);
});

test('negative levels are read as zero and a level at zero never fires', function () {
    config(['security.disk_alert.critical_free_gb' => -5, 'security.disk_alert.warn_free_gb' => 0]);
    dsaFree(0);

    $this->artisan('security:check-disk')
        ->expectsOutput('Disk space OK: 0.0 GB free of 35.0 GB.')
        ->assertSuccessful();

    expect(dsaSent())->toBe([]);
});

test('an unreadable disk fails with a plain message', function () {
    $this->mock(DiskSpace::class, fn ($mock) => $mock->shouldReceive('read')->once()->andReturn(null));

    $this->artisan('security:check-disk')
        ->expectsOutput('Could not read the free space of the storage disk; no disk check done.')
        ->assertFailed();

    expect(dsaSent())->toBe([]);
});

test('no usable address fails only when an alert is due', function (mixed $email) {
    config(['security.owner_alerts.email' => $email]);

    dsaFree(8053063680);
    $this->artisan('security:check-disk')
        ->expectsOutput('SECURITY_OWNER_ALERTS_EMAIL is not a valid email address; no disk alert sent.')
        ->assertFailed();

    expect(dsaSent())->toBe([]);
    expect(Cache::has('security-disk-alert:warning:2026-11-16'))->toBeFalse();

    dsaFree(21474836480);
    $this->artisan('security:check-disk')
        ->expectsOutput('Disk space OK: 20.0 GB free of 35.0 GB.')
        ->assertSuccessful();
})->with([
    'null' => [null],
    'empty' => [''],
    'not an email' => ['not-an-email'],
    'an array' => [['a@b.test']],
    'two addresses' => ['a@b.test,c@d.test'],
]);

test('a mail that cannot be sent gives the day back, is reported and fails', function () {
    Exceptions::fake();
    dsaFree(3221225472);
    Notification::swap(Mockery::mock(ChannelManager::class, fn ($mock) => $mock
        ->shouldReceive('send')->once()->andThrow(new RuntimeException('smtp down'))
        ->getMock()));

    $this->artisan('security:check-disk')
        ->expectsOutput('The critical disk alert could not be sent.')
        ->assertFailed();

    Exceptions::assertReported(RuntimeException::class);
    expect(Cache::has('security-disk-alert:critical:2026-11-16'))->toBeFalse();
    expect(glob($this->dsaDir.'/security-*.log'))->toBe([]);
});

test('a broken cache still sends the alert', function () {
    Exceptions::fake();
    dsaFree(8053063680);
    $store = new DsaBrokenCacheStore;
    Cache::extend('dsa-broken', fn () => Cache::repository($store));
    config(['cache.stores.dsa-broken' => ['driver' => 'dsa-broken'], 'cache.default' => 'dsa-broken']);
    Cache::purge();

    $this->artisan('security:check-disk')
        ->expectsOutput('Warning disk alert sent to the owner address: 7.5 GB free.')
        ->assertSuccessful();

    Exceptions::assertReported(RuntimeException::class);
    expect(dsaSent())->toHaveCount(1);
    expect($store->forgotten)->toBe(0);

    Cache::purge();
});

test('a log that cannot be written never turns a sent alert into a failure', function () {
    Exceptions::fake();
    dsaFree(3221225472);
    config(['logging.channels.security' => ['driver' => 'single', 'path' => '/proc/no-such-dir/security.log', 'level' => 'info']]);
    Log::forgetChannel('security');

    $this->artisan('security:check-disk')
        ->expectsOutput('Critical disk alert sent to the owner address: 3.0 GB free.')
        ->assertSuccessful();

    Exceptions::assertReported(UnexpectedValueException::class);
    expect(dsaSent())->toHaveCount(1);
});

test('a sent alert leaves one audit line without the address', function () {
    dsaFree(3221225472);

    $this->artisan('security:check-disk')->assertSuccessful();

    $files = glob($this->dsaDir.'/security-*.log');
    expect($files)->toHaveCount(1);
    $log = File::get($files[0]);
    expect($log)->toContain('security.disk_alert_sent');
    expect($log)->toContain('"level":"critical","free_gb":3.0,"total_gb":35.0');
    expect($log)->not->toContain('ops@example.com');
    expect(substr_count($log, 'security.disk_alert_sent'))->toBe(1);
});

test('nothing is logged when no alert is sent', function () {
    dsaFree(21474836480);

    $this->artisan('security:check-disk')->assertSuccessful();

    expect(glob($this->dsaDir.'/security-*.log'))->toBe([]);
});

test('the schedule runs it hourly at :40 without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'security:check-disk'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('40 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
});

test('the real reader returns numbers for storage_path() and null for a missing folder', function () {
    $reading = (new DiskSpace)->read(storage_path());

    expect($reading)->not->toBeNull();
    expect($reading['free'])->toBeFloat();
    expect($reading['total'])->toBeFloat()->toBeGreaterThan(0.0);
    expect($reading['free'])->toBeLessThanOrEqual($reading['total']);
    expect((new DiskSpace)->read('/no/such/dir-sec47'))->toBeNull();
});

test('a byte is counted in GiB', function () {
    expect(DiskSpace::BYTES_PER_GB)->toBe(1024 ** 3);
});

test('the example env ships the three keys with the owner values', function () {
    $env = File::get(base_path('.env.example'));

    expect($env)->toContain("SECURITY_DISK_ALERT_ENABLED=true\n")
        ->toContain("SECURITY_DISK_WARN_FREE_GB=8\n")
        ->toContain("SECURITY_DISK_CRITICAL_FREE_GB=4\n");
});

test('the config ships the documented defaults', function () {
    $source = File::get(config_path('security.php'));

    expect($source)->toContain("env('SECURITY_DISK_ALERT_ENABLED', true)")
        ->toContain("(float) env('SECURITY_DISK_WARN_FREE_GB', 8)")
        ->toContain("(float) env('SECURITY_DISK_CRITICAL_FREE_GB', 4)");
});
