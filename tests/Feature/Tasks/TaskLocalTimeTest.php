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

        expect($keys)->toBe(["due-soon:{$dueTomorrow->id}:2026-03-16", "overdue:{$overdue->id}:2026-03-15"]);
    });

    it('key the overdue reminder with the local date', function () {
        $this->travelTo(now()->setDate(2026, 3, 14)->setTime(17, 0));
        $kiritimati = Organization::factory()->timezone('Pacific/Kiritimati')->create(['created_by' => $this->parisOwner->id]);
        $kiritimati->members()->attach($this->parisOwner, ['role' => 'owner']);
        $overdue = ($this->taskOf)($kiritimati, '2026-03-14 10:00:00');

        Artisan::call('tasks:send-reminders');

        $keys = DatabaseNotification::where('notifiable_id', $this->parisOwner->id)->get()->pluck('data.notification_key')->all();

        expect($keys)->toBe(["overdue:{$overdue->id}:2026-03-15"]);
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
