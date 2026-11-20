<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDailyDigestNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['tasks.notifications.send_email' => true, 'tasks.notifications.daily_digest_enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-02-21 08:00:00', 'UTC'));

    $this->ann = User::factory()->onboardingCompleted()->create(['email' => 'ann@example.com']);
    $this->org = Organization::factory()->timezone('UTC')->create(['created_by' => $this->ann->id]);
    $this->org->members()->attach($this->ann, ['role' => OrganizationRole::Owner->value]);

    $this->taskFor = fn (string $title, ?User $assignee, string $dueAtUtc): Task => Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->ann->id,
        'owner_id' => $assignee?->id,
        'title' => $title,
        'status' => TaskStatus::Pending,
        'due_at' => $dueAtUtc,
    ]);

    $this->channelsOf = function (User $user, string $class, string $title): ?array {
        $found = null;

        Notification::assertSentTo($user, $class, function ($notification, array $channels) use ($title, &$found): bool {
            if ($notification->task->title === $title) {
                $found = $channels;
            }

            return true;
        });

        return $found;
    };

    $this->setSetting = fn (User $user, bool $value) => $user->forceFill(['task_reminder_mails' => $value])->save();
});

test('due-soon and overdue keep the bell and drop the mail when the setting is off', function (bool $setting, array $channels) {
    Notification::fake();
    ($this->setSetting)($this->ann, $setting);
    ($this->taskFor)('Call Acme', $this->ann, '2026-02-22 10:00:00');
    ($this->taskFor)('Send quote', $this->ann, '2026-02-21 07:30:00');

    Artisan::call('tasks:send-reminders', ['--all' => true]);

    expect(($this->channelsOf)($this->ann, TaskDueSoonNotification::class, 'Call Acme'))->toBe($channels)
        ->and(($this->channelsOf)($this->ann, TaskOverdueNotification::class, 'Send quote'))->toBe($channels);
})->with([
    'on' => [true, ['database', 'mail']],
    'off' => [false, ['database']],
]);

test('the bell row is still written when the setting is off', function () {
    ($this->setSetting)($this->ann, false);
    ($this->taskFor)('Send quote', $this->ann, '2026-02-21 07:30:00');

    Artisan::call('tasks:send-reminders', ['--all' => true]);
    Artisan::call('tasks:send-reminders', ['--all' => true]);

    expect($this->ann->notifications()->count())->toBe(1);
});

test('the daily digest is not sent at all when the setting is off', function () {
    Notification::fake();
    ($this->setSetting)($this->ann, false);
    ($this->taskFor)('Send quote', $this->ann, '2026-02-21 07:30:00');

    Artisan::call('tasks:send-digest', ['--all' => true]);

    Notification::assertNotSentTo($this->ann, TaskDailyDigestNotification::class);
    Notification::assertNothingSent();
});

test('the daily digest is still sent when the setting is on', function () {
    Notification::fake();
    ($this->taskFor)('Send quote', $this->ann, '2026-02-21 07:30:00');

    Artisan::call('tasks:send-digest', ['--all' => true]);

    Notification::assertSentTo($this->ann, TaskDailyDigestNotification::class, fn (TaskDailyDigestNotification $notification, array $channels): bool => $channels === ['mail']
        && $notification->overdueTasks->pluck('title')->all() === ['Send quote']);
});

test('one member opting out does not stop the mails of another member', function () {
    Notification::fake();
    $bob = User::factory()->onboardingCompleted()->create(['email' => 'bob@example.com']);
    $this->org->members()->attach($bob, ['role' => OrganizationRole::Admin->value]);
    ($this->setSetting)($this->ann, false);
    ($this->taskFor)('Send quote', null, '2026-02-21 07:30:00');

    Artisan::call('tasks:send-reminders', ['--all' => true]);
    Artisan::call('tasks:send-digest', ['--all' => true]);

    expect(($this->channelsOf)($bob, TaskOverdueNotification::class, 'Send quote'))->toBe(['database', 'mail'])
        ->and(($this->channelsOf)($this->ann, TaskOverdueNotification::class, 'Send quote'))->toBe(['database']);
    Notification::assertSentTo($bob, TaskDailyDigestNotification::class);
    Notification::assertNotSentTo($this->ann, TaskDailyDigestNotification::class);
});

test('an unverified member gets the bell but no task email', function () {
    Notification::fake();
    $carl = User::factory()->unverified()->onboardingCompleted()->create(['email' => 'carl@example.com']);
    $this->org->members()->attach($carl, ['role' => OrganizationRole::Member->value]);
    ($this->taskFor)('Send quote', null, '2026-02-21 07:30:00');

    Artisan::call('tasks:send-reminders', ['--all' => true]);
    Artisan::call('tasks:send-digest', ['--all' => true]);

    expect(($this->channelsOf)($carl, TaskOverdueNotification::class, 'Send quote'))->toBe(['database']);
    Notification::assertNotSentTo($carl, TaskDailyDigestNotification::class);
    Notification::assertSentTo($this->ann, TaskDailyDigestNotification::class);
});

test('the global switch still wins', function () {
    Notification::fake();
    config(['tasks.notifications.send_email' => false]);
    ($this->taskFor)('Send quote', $this->ann, '2026-02-21 07:30:00');

    Artisan::call('tasks:send-reminders', ['--all' => true]);

    expect(($this->channelsOf)($this->ann, TaskOverdueNotification::class, 'Send quote'))->toBe(['database']);
});

test('a notifiable that is not a user gets no task mail', function () {
    $task = ($this->taskFor)('Send quote', null, '2026-02-21 07:30:00');
    $notification = new TaskOverdueNotification($task, $this->org, 'key');

    expect($notification->via(new stdClass))->toBe(['database'])
        ->and($notification->shouldSend(new stdClass, 'mail'))->toBeFalse()
        ->and($notification->shouldSend(new stdClass, 'database'))->toBeTrue();
});

test('a mail queued before the user opted out is not delivered', function (string $class) {
    $task = ($this->taskFor)('Send quote', $this->ann, '2026-02-21 07:30:00');
    $notification = $class === TaskDailyDigestNotification::class
        ? new TaskDailyDigestNotification($this->org, collect([$task]), collect())
        : new $class($task, $this->org, 'overdue:'.$task->id.':2026-02-21T07:30Z');

    ($this->setSetting)($this->ann, false);
    Event::fake([NotificationSent::class]);

    Notification::sendNow($this->ann->fresh(), $notification, ['mail']);

    Event::assertNotDispatched(NotificationSent::class);
    expect($notification->shouldSend($this->ann->fresh(), 'database'))->toBeTrue()
        ->and($notification->shouldSend($this->ann->fresh(), 'mail'))->toBeFalse();
})->with([TaskOverdueNotification::class, TaskDueSoonNotification::class, TaskDailyDigestNotification::class]);

test('a mail queued while the setting is on is delivered', function () {
    $task = ($this->taskFor)('Send quote', $this->ann, '2026-02-21 07:30:00');
    $notification = new TaskOverdueNotification($task, $this->org, 'overdue:'.$task->id.':2026-02-21T07:30Z');
    Event::fake([NotificationSent::class]);

    Notification::sendNow($this->ann->fresh(), $notification, ['mail']);

    Event::assertDispatched(NotificationSent::class, fn (NotificationSent $event): bool => $event->channel === 'mail');
});

test('a mail queued before the address lost its verification is not delivered', function () {
    $task = ($this->taskFor)('Send quote', $this->ann, '2026-02-21 07:30:00');
    $notification = new TaskOverdueNotification($task, $this->org, 'key');
    $this->ann->forceFill(['email_verified_at' => null])->save();
    Event::fake([NotificationSent::class]);

    Notification::sendNow($this->ann->fresh(), $notification, ['mail']);

    Event::assertNotDispatched(NotificationSent::class);
});

test('the mail goes to the verified address, never to the pending one', function () {
    $this->ann->forceFill(['pending_email' => 'new@example.com'])->save();

    expect($this->ann->fresh()->routeNotificationFor('mail'))->toBe('ann@example.com');
});

test('no mail channel is even chosen for an opted-out or unverified user', function (string $class) {
    $task = ($this->taskFor)('Send quote', $this->ann, '2026-02-21 07:30:00');
    $notification = $class === TaskDailyDigestNotification::class
        ? new TaskDailyDigestNotification($this->org, collect([$task]), collect())
        : new $class($task, $this->org, 'key');
    $mail = $class === TaskDailyDigestNotification::class ? ['mail'] : ['database', 'mail'];
    $none = $class === TaskDailyDigestNotification::class ? [] : ['database'];
    $unverified = User::factory()->unverified()->create();

    expect($notification->via($this->ann))->toBe($mail);

    ($this->setSetting)($this->ann, false);

    expect($notification->via($this->ann->fresh()))->toBe($none)
        ->and($notification->via($unverified))->toBe($none);
})->with([TaskOverdueNotification::class, TaskDueSoonNotification::class, TaskDailyDigestNotification::class]);

test('the switch is read as a real boolean', function () {
    $this->ann->forceFill(['task_reminder_mails' => '0']);

    expect($this->ann->task_reminder_mails)->toBeFalse();

    $this->ann->forceFill(['task_reminder_mails' => '1']);

    expect($this->ann->task_reminder_mails)->toBeTrue();
});
