<?php

use App\Models\Organization;
use App\Models\User;
use App\Notifications\QueueWaitAlertNotification;
use App\Support\Security\QueueWaitAlert;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Laravel\Horizon\Events\LongWaitDetected;

class QwaBrokenCacheStore extends ArrayStore
{
    public function get($key)
    {
        throw new RuntimeException('cache down');
    }

    public function add($key, $value, $seconds)
    {
        throw new RuntimeException('cache down');
    }
}

function qwaHandle(string $connection, string $queue, int $seconds): void
{
    app(QueueWaitAlert::class)->handle(new LongWaitDetected($connection, $queue, $seconds));
}

/**
 * @return list<QueueWaitAlertNotification>
 */
function qwaSent(): array
{
    return collect(Notification::sentNotifications()[AnonymousNotifiable::class] ?? [])
        ->flatten(2)
        ->pluck('notification')
        ->filter(fn ($notification): bool => $notification instanceof QueueWaitAlertNotification)
        ->values()
        ->all();
}

/**
 * @return list<string>
 */
function qwaLines(QueueWaitAlertNotification $notification): array
{
    return $notification->toMail(new AnonymousNotifiable)->introLines;
}

beforeEach(function () {
    $this->qwaDir = sys_get_temp_dir().'/queue-wait-alert-'.bin2hex(random_bytes(6));
    File::makeDirectory($this->qwaDir);

    config([
        'app.name' => 'Acme CRM',
        'logging.channels.security' => ['driver' => 'daily', 'path' => $this->qwaDir.'/security.log', 'level' => 'info', 'days' => 90],
        'security.queue_wait_alert.enabled' => true,
        'security.owner_alerts.email' => 'ops@example.test',
        'horizon.waits' => [
            'redis:default' => 60,
            'redis-long:segments' => 900,
            'redis-long:imports' => 1800,
            'redis-long:exports' => 900,
        ],
    ]);
    Log::forgetChannel('security');

    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'));
    Notification::fake();
});

afterEach(function () {
    Log::forgetChannel('security');
    File::deleteDirectory($this->qwaDir);
});

test('a backed-up queue mails the owner once with the exact text', function (string $connection, string $queue, int $seconds, string $minutes, string $level, string $consequence) {
    qwaHandle($connection, $queue, $seconds);

    Notification::assertSentOnDemand(QueueWaitAlertNotification::class, function ($notification, $channels, $notifiable) use ($queue, $minutes, $level, $consequence): bool {
        expect($notifiable->routes['mail'])->toBe('ops@example.test');
        expect($channels)->toBe(['mail']);
        expect($notification->toMail($notifiable)->subject)->toBe("Queue delay: \"{$queue}\" on Acme CRM");
        expect(qwaLines($notification))->toBe([
            "The \"{$queue}\" queue of Acme CRM needs about {$minutes} to work through the jobs waiting on it, more than the alert level of {$level}.",
            $consequence,
            'Open Horizon at '.url('horizon').' to see which jobs are waiting and whether the workers are running.',
            'You get at most one alert per queue an hour.',
        ]);

        return true;
    });

    expect(qwaSent())->toHaveCount(1);

    $files = glob($this->qwaDir.'/security-*.log');
    expect($files)->toHaveCount(1);
    $log = File::get($files[0]);
    expect($log)->toContain('security.queue_wait_alert_sent')
        ->toContain('"connection":"'.$connection.'","queue":"'.$queue.'","seconds":'.$seconds)
        ->not->toContain('ops@example.test');
    expect(substr_count($log, 'security.queue_wait_alert_sent'))->toBe(1);
})->with([
    'imports' => ['redis-long', 'imports', 2400, '40 minutes', '30 minutes', 'Imports that users queued are waiting to start.'],
    'exports' => ['redis-long', 'exports', 901, '16 minutes', '15 minutes', 'Exports that users queued are waiting to be prepared.'],
    'segments' => ['redis-long', 'segments', 1000, '17 minutes', '15 minutes', 'Segment member lists stay out of date until the queue clears.'],
    'default' => ['redis', 'default', 75, '2 minutes', '1 minute', 'Mails, notifications and segment re-checks are delayed.'],
]);

test('an unknown queue gets the generic line and the 60 second fallback level', function () {
    qwaHandle('redis', 'reports', 61);

    $sent = qwaSent();
    expect($sent)->toHaveCount(1);
    expect(qwaLines($sent[0]))->toHaveCount(4);
    expect(qwaLines($sent[0])[0])->toEndWith('more than the alert level of 1 minute.');
    expect(qwaLines($sent[0])[1])->toBe('Jobs on this queue are delayed.');
});

test('a wait under a minute is shown as one minute', function () {
    qwaHandle('redis', 'default', 0);

    expect(qwaLines(qwaSent()[0])[0])->toContain('needs about 1 minute to work');
});

test('the mail is sent at once, not queued', function () {
    expect(new QueueWaitAlertNotification('redis', 'default', 75))->not->toBeInstanceOf(ShouldQueue::class);
});

test('a queue that stays backed up mails once an hour, not every minute', function () {
    qwaHandle('redis-long', 'imports', 2400);
    qwaHandle('redis-long', 'imports', 2400);
    qwaHandle('redis-long', 'imports', 2400);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:59:00', 'UTC'));
    qwaHandle('redis-long', 'imports', 2400);

    expect(qwaSent())->toHaveCount(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-18 11:00:01', 'UTC'));
    qwaHandle('redis-long', 'imports', 2400);

    expect(qwaSent())->toHaveCount(2);
});

test('each queue has its own hourly slot', function () {
    qwaHandle('redis-long', 'imports', 2400);
    qwaHandle('redis-long', 'exports', 1000);

    expect(collect(qwaSent())->pluck('queue')->all())->toBe(['imports', 'exports']);
});

test('the same queue name on another connection has its own slot', function () {
    qwaHandle('redis', 'imports', 2400);
    qwaHandle('redis-long', 'imports', 2400);

    expect(qwaSent())->toHaveCount(2);
});

test('no address or a turned-off alert sends nothing, reports nothing and takes no slot', function (mixed $email, bool $enabled) {
    Exceptions::fake();
    config(['security.owner_alerts.email' => $email, 'security.queue_wait_alert.enabled' => $enabled]);

    qwaHandle('redis-long', 'imports', 2400);

    expect(qwaSent())->toBe([]);
    Exceptions::assertNothingReported();
    expect(Cache::has('queue-wait-alert:redis-long:imports'))->toBeFalse();
    expect(glob($this->qwaDir.'/security-*.log'))->toBe([]);
})->with([
    'null' => [null, true],
    'empty' => ['', true],
    'not an address' => ['not-an-address', true],
    'an array' => [['a@b.test'], true],
    'two addresses' => ['a@b.test,c@d.test', true],
    'toggle off' => ['ops@example.test', false],
]);

test('a failing mailer never breaks the supervisor and the next check retries', function () {
    Exceptions::fake();
    Notification::swap(Mockery::mock(ChannelManager::class, fn ($mock) => $mock
        ->shouldReceive('send')->once()->andThrow(new RuntimeException('smtp down'))
        ->getMock()));

    qwaHandle('redis-long', 'imports', 2400);

    Exceptions::assertReported(RuntimeException::class);
    expect(Cache::has('queue-wait-alert:redis-long:imports'))->toBeFalse();
    expect(glob($this->qwaDir.'/security-*.log'))->toBe([]);

    Notification::swap(new ChannelManager(app()));
    Notification::fake();
    qwaHandle('redis-long', 'imports', 2400);

    expect(qwaSent())->toHaveCount(1);
});

test('a broken cache still sends the alert and reports the cache failure', function () {
    Exceptions::fake();
    Cache::extend('qwa-broken', fn () => Cache::repository(new QwaBrokenCacheStore));
    config(['cache.stores.qwa-broken' => ['driver' => 'qwa-broken'], 'cache.default' => 'qwa-broken']);
    Cache::purge();

    qwaHandle('redis-long', 'exports', 1000);

    Exceptions::assertReported(RuntimeException::class);
    expect(qwaSent())->toHaveCount(1);

    Cache::purge();
});

test('a log that cannot be written never turns a sent alert into a failure', function () {
    Exceptions::fake();
    config(['logging.channels.security' => ['driver' => 'single', 'path' => '/proc/no-such-dir/security.log', 'level' => 'info']]);
    Log::forgetChannel('security');

    qwaHandle('redis-long', 'imports', 2400);

    Exceptions::assertReported(UnexpectedValueException::class);
    expect(qwaSent())->toHaveCount(1);
});

test('a malformed event never throws out of the supervisor loop', function () {
    Exceptions::fake();
    $event = new LongWaitDetected('redis-long', 'imports', 2400);
    $event->queue = ['imports'];

    app(QueueWaitAlert::class)->handle($event);

    Exceptions::assertReported(ErrorException::class);
    expect(qwaSent())->toBe([]);
});

test('the mail carries no tenant data', function () {
    $organization = Organization::factory()->create(['name' => 'Globex Demo']);
    $user = User::factory()->create(['email' => 'other@example.com']);

    qwaHandle('redis-long', 'imports', 2400);

    $message = qwaSent()[0]->toMail(new AnonymousNotifiable);
    $text = $message->subject."\n".implode("\n", $message->introLines);

    expect($text)->not->toContain('Globex Demo')->not->toContain('other@example.com')->not->toContain((string) $organization->name);
    expect($user->email)->toBe('other@example.com');
});

test('the example env ships the toggle and the four levels with the planned values', function () {
    $env = File::get(base_path('.env.example'));

    expect($env)->toContain("SECURITY_QUEUE_WAIT_ALERT_ENABLED=true\n")
        ->toContain("HORIZON_WAIT_DEFAULT_SECONDS=60\n")
        ->toContain("HORIZON_WAIT_SEGMENTS_SECONDS=900\n")
        ->toContain("HORIZON_WAIT_IMPORTS_SECONDS=1800\n")
        ->toContain("HORIZON_WAIT_EXPORTS_SECONDS=900\n");
});
