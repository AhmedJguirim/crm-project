<?php

use App\Enums\TaskStatus;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDailyDigestNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['tasks.notifications.send_email' => false, 'tasks.notifications.daily_digest_enabled' => true]);

    $this->parisOwner = User::factory()->onboardingCompleted()->create();
    $this->newYorkOwner = User::factory()->onboardingCompleted()->create();
    $this->paris = Organization::factory()->timezone('Europe/Paris')->create(['created_by' => $this->parisOwner->id]);
    $this->newYork = Organization::factory()->timezone('America/New_York')->create(['created_by' => $this->newYorkOwner->id]);
    $this->paris->members()->attach($this->parisOwner, ['role' => 'owner']);
    $this->newYork->members()->attach($this->newYorkOwner, ['role' => 'owner']);

    $this->taskOf = fn (Organization $organization, string $dueAtUtc): Task => Task::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $organization->created_by,
        'status' => TaskStatus::Pending,
        'due_at' => $dueAtUtc,
    ]);
});

describe('reminders', function () {
    it('go out at local 07:00', function () {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(6, 0));
        ($this->taskOf)($this->paris, '2026-03-16 10:00:00');
        ($this->taskOf)($this->newYork, '2026-03-16 16:00:00');
        Notification::fake();

        Artisan::call('tasks:send-reminders');

        Notification::assertSentTo($this->parisOwner, TaskDueSoonNotification::class);
        Notification::assertNotSentTo($this->newYorkOwner, TaskDueSoonNotification::class);
    });

    it('go out to every organization with --all', function () {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(6, 0));
        ($this->taskOf)($this->paris, '2026-03-16 10:00:00');
        ($this->taskOf)($this->newYork, '2026-03-16 16:00:00');
        Notification::fake();

        Artisan::call('tasks:send-reminders', ['--all' => true]);

        Notification::assertSentTo($this->parisOwner, TaskDueSoonNotification::class);
        Notification::assertSentTo($this->newYorkOwner, TaskDueSoonNotification::class);
    });

    it('treat the local tomorrow as tomorrow', function () {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(6, 0));
        $afterLocalTomorrow = ($this->taskOf)($this->paris, '2026-03-16 23:30:00');
        $startOfLocalTomorrow = ($this->taskOf)($this->paris, '2026-03-15 23:30:00');
        Notification::fake();

        Artisan::call('tasks:send-reminders');

        Notification::assertSentTo($this->parisOwner, TaskDueSoonNotification::class, fn (TaskDueSoonNotification $notification): bool => $notification->task->is($startOfLocalTomorrow));
        Notification::assertNotSentTo($this->parisOwner, TaskDueSoonNotification::class, fn (TaskDueSoonNotification $notification): bool => $notification->task->is($afterLocalTomorrow));
    });

    it('are not duplicated when the command runs twice the same local day', function () {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(6, 0));
        $dueTomorrow = ($this->taskOf)($this->paris, '2026-03-16 10:00:00');
        $overdue = ($this->taskOf)($this->paris, '2026-03-14 10:00:00');

        Artisan::call('tasks:send-reminders', ['--all' => true]);
        Artisan::call('tasks:send-reminders', ['--all' => true]);

        $keys = DatabaseNotification::where('notifiable_id', $this->parisOwner->id)->get()->pluck('data.notification_key')->sort()->values()->all();

        expect($keys)->toBe(["due-soon:{$dueTomorrow->id}:2026-03-16", "overdue:{$overdue->id}:2026-03-14T10:00Z"]);
    });

    it('key the overdue reminder with its due instant in UTC', function () {
        $this->travelTo(now()->setDate(2026, 3, 14)->setTime(17, 0));
        $kiritimati = Organization::factory()->timezone('Pacific/Kiritimati')->create(['created_by' => $this->parisOwner->id]);
        $kiritimati->members()->attach($this->parisOwner, ['role' => 'owner']);
        $overdue = ($this->taskOf)($kiritimati, '2026-03-14 10:00:00');

        Artisan::call('tasks:send-reminders');

        $keys = DatabaseNotification::where('notifiable_id', $this->parisOwner->id)->get()->pluck('data.notification_key')->all();

        expect($keys)->toBe(["overdue:{$overdue->id}:2026-03-14T10:00Z"]);
    });

    it('use the configured hour', function () {
        config(['tasks.notifications.reminder_time' => '09:30']);
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(8, 15));
        ($this->taskOf)($this->paris, '2026-03-16 10:00:00');
        Notification::fake();

        Artisan::call('tasks:send-reminders');

        Notification::assertSentTo($this->parisOwner, TaskDueSoonNotification::class);
    });

    it('still list overdue tasks by instant', function () {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(6, 0));
        $overdue = ($this->taskOf)($this->paris, '2026-03-15 05:59:00');
        Notification::fake();

        Artisan::call('tasks:send-reminders');

        Notification::assertSentTo($this->parisOwner, TaskOverdueNotification::class, fn (TaskOverdueNotification $notification): bool => $notification->task->is($overdue));
    });
});

describe('the overdue reminder', function () {
    beforeEach(function () {
        $this->overdueNotifications = fn (): Collection => DatabaseNotification::query()
            ->where('notifiable_id', $this->parisOwner->id)
            ->where('type', TaskOverdueNotification::class)
            ->get();
    });

    it('is sent once, on the run right after the task became overdue', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 06:00:00', 'UTC'));
        $task = ($this->taskOf)($this->paris, '2026-03-14 10:00:00');

        Artisan::call('tasks:send-reminders');

        expect(($this->overdueNotifications)()->pluck('data.notification_key')->all())->toBe(["overdue:{$task->id}:2026-03-14T10:00Z"]);

        $this->travelTo(CarbonImmutable::parse('2026-03-16 06:00:00', 'UTC'));
        Artisan::call('tasks:send-reminders');

        expect(($this->overdueNotifications)())->toHaveCount(1);
    });

    it('is not sent again by --all runs the same day', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 06:00:00', 'UTC'));
        ($this->taskOf)($this->paris, '2026-03-14 10:00:00');
        Artisan::call('tasks:send-reminders');

        $this->travelTo(CarbonImmutable::parse('2026-03-15 09:00:00', 'UTC'));
        Artisan::call('tasks:send-reminders', ['--all' => true]);
        Artisan::call('tasks:send-reminders', ['--all' => true]);

        expect(($this->overdueNotifications)())->toHaveCount(1);
    });

    it('only covers tasks due since the start of the previous run hour', function (string $now, string $due, int $count) {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
        ($this->taskOf)($this->paris, $due);

        Artisan::call('tasks:send-reminders');

        expect(($this->overdueNotifications)())->toHaveCount($count);
    })->with([
        'exactly the window start' => ['2026-03-15 06:00:00', '2026-03-14 06:00:00', 1],
        'a minute before the window' => ['2026-03-15 06:00:00', '2026-03-14 05:59:00', 0],
        'a stale task' => ['2026-03-15 06:00:00', '2026-03-10 09:00:00', 0],
        'overdue by one minute' => ['2026-03-15 06:00:00', '2026-03-15 05:59:00', 1],
        'DST spring, window start' => ['2026-03-29 05:00:00', '2026-03-28 06:00:00', 1],
        'DST spring, before the window' => ['2026-03-29 05:00:00', '2026-03-28 05:59:00', 0],
        'DST autumn, window start' => ['2026-10-25 06:00:00', '2026-10-24 05:00:00', 1],
        'DST autumn, before the window' => ['2026-10-25 06:00:00', '2026-10-24 04:59:00', 0],
        'run mid-hour, due after the hour start' => ['2026-03-15 06:30:00', '2026-03-14 06:10:00', 1],
        'run mid-hour, due before the hour start' => ['2026-03-15 06:30:00', '2026-03-14 05:59:00', 0],
        'not yet overdue' => ['2026-03-15 06:00:00', '2026-03-15 06:00:00', 0],
    ]);

    it('is not sent for a done task', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 06:00:00', 'UTC'));
        $task = ($this->taskOf)($this->paris, '2026-03-14 10:00:00');
        $task->forceFill(['status' => TaskStatus::Done])->save();

        Artisan::call('tasks:send-reminders');

        expect(($this->overdueNotifications)())->toHaveCount(0);
    });

    it('is not sent for a task without a due date', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 06:00:00', 'UTC'));
        Task::factory()->create([
            'organization_id' => $this->paris->id,
            'created_by' => $this->paris->created_by,
            'status' => TaskStatus::Pending,
            'due_at' => null,
        ]);

        Artisan::call('tasks:send-reminders');

        expect(($this->overdueNotifications)())->toHaveCount(0);
    });

    it('is sent once more for a new due date, and only once for it', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 06:00:00', 'UTC'));
        $task = ($this->taskOf)($this->paris, '2026-03-14 10:00:00');
        Artisan::call('tasks:send-reminders');

        $task->forceFill(['due_at' => '2026-03-15 12:00:00'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-03-16 06:00:00', 'UTC'));
        Artisan::call('tasks:send-reminders');

        expect(($this->overdueNotifications)()->pluck('data.notification_key')->sort()->values()->all())
            ->toBe(["overdue:{$task->id}:2026-03-14T10:00Z", "overdue:{$task->id}:2026-03-15T12:00Z"]);

        $this->travelTo(CarbonImmutable::parse('2026-03-17 06:00:00', 'UTC'));
        Artisan::call('tasks:send-reminders');

        expect(($this->overdueNotifications)())->toHaveCount(2);
    });

    it('keeps its key when the organization changes timezone', function () {
        $this->travelTo(CarbonImmutable::parse('2026-03-15 06:00:00', 'UTC'));
        $task = ($this->taskOf)($this->paris, '2026-03-14 10:00:00');
        Artisan::call('tasks:send-reminders');

        $this->paris->update(['timezone' => 'Asia/Tokyo']);
        $this->travelTo(CarbonImmutable::parse('2026-03-15 22:00:00', 'UTC'));
        Artisan::call('tasks:send-reminders', ['--all' => true]);

        expect(($this->overdueNotifications)()->pluck('data.notification_key')->all())->toBe(["overdue:{$task->id}:2026-03-14T10:00Z"]);
    });
});

describe('an hour skipped by a DST change', function () {
    it('still gets the reminders in the first hour after the gap', function () {
        config(['tasks.notifications.reminder_time' => '02:00']);
        $this->travelTo(CarbonImmutable::parse('2026-03-29 01:05', 'UTC'));
        ($this->taskOf)($this->paris, '2026-03-30 10:00:00');
        Notification::fake();

        Artisan::call('tasks:send-reminders');

        Notification::assertSentTo($this->parisOwner, TaskDueSoonNotification::class);
    });

    it('still gets the digest in the first hour after the gap', function () {
        config(['tasks.notifications.daily_digest_time' => '02:00']);
        $this->travelTo(CarbonImmutable::parse('2026-03-29 01:05', 'UTC'));
        ($this->taskOf)($this->paris, '2026-03-29 12:00:00');
        Notification::fake();

        Artisan::call('tasks:send-digest');

        Notification::assertSentTo($this->parisOwner, TaskDailyDigestNotification::class);
    });

    it('does not send a second time an hour later', function () {
        config(['tasks.notifications.daily_digest_time' => '02:00']);
        $this->travelTo(CarbonImmutable::parse('2026-03-29 02:05', 'UTC'));
        ($this->taskOf)($this->paris, '2026-03-29 12:00:00');
        Notification::fake();

        Artisan::call('tasks:send-digest');

        Notification::assertNotSentTo($this->parisOwner, TaskDailyDigestNotification::class);
    });
});

describe('the digest', function () {
    it('goes out at the configured local hour', function () {
        config(['tasks.notifications.daily_digest_time' => '08:00']);
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(7, 0));
        ($this->taskOf)($this->paris, '2026-03-15 12:00:00');
        ($this->taskOf)($this->newYork, '2026-03-15 12:00:00');
        Notification::fake();

        Artisan::call('tasks:send-digest');

        Notification::assertSentTo($this->parisOwner, TaskDailyDigestNotification::class);
        Notification::assertNotSentTo($this->newYorkOwner, TaskDailyDigestNotification::class);
    });

    it('goes out to every organization with --all', function () {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(7, 0));
        ($this->taskOf)($this->paris, '2026-03-15 12:00:00');
        ($this->taskOf)($this->newYork, '2026-03-15 12:00:00');
        Notification::fake();

        Artisan::call('tasks:send-digest', ['--all' => true]);

        Notification::assertSentTo($this->parisOwner, TaskDailyDigestNotification::class);
        Notification::assertSentTo($this->newYorkOwner, TaskDailyDigestNotification::class);
    });

    it('lists the tasks due on the local today', function () {
        $this->travelTo(now()->setDate(2026, 3, 15)->setTime(7, 0));
        $today = ($this->taskOf)($this->paris, '2026-03-15 12:00:00');
        $localTomorrow = ($this->taskOf)($this->paris, '2026-03-15 23:30:00');
        Notification::fake();

        Artisan::call('tasks:send-digest', ['--all' => true]);

        Notification::assertSentTo($this->parisOwner, TaskDailyDigestNotification::class, fn (TaskDailyDigestNotification $notification): bool => $notification->dueTodayTasks->modelKeys() === [$today->id]
            && ! $notification->dueTodayTasks->contains($localTomorrow));
    });
});
