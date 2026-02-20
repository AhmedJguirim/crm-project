<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDailyDigestNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 08:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('reminders command sends due-soon and overdue notifications', function () {
    Notification::fake();

    $dueTomorrowTask = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay()->setTime(10, 0),
        'type' => TaskType::FollowUp,
    ]);

    $overdueTask = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->subDay(),
        'type' => TaskType::Call,
    ]);

    Artisan::call('tasks:send-reminders');

    Notification::assertSentTo($this->user, TaskDueSoonNotification::class, function (TaskDueSoonNotification $notification) use ($dueTomorrowTask) {
        return $notification->task->is($dueTomorrowTask)
            && $notification->organization->is($this->org);
    });

    Notification::assertSentTo($this->user, TaskOverdueNotification::class, function (TaskOverdueNotification $notification) use ($overdueTask) {
        return $notification->task->is($overdueTask)
            && $notification->organization->is($this->org);
    });
});

test('reminders command does not send notifications for other organizations', function () {
    Notification::fake();

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    Task::withoutGlobalScope('organization')->create([
        'organization_id' => $otherOrg->id,
        'title' => 'Other org due task',
        'type' => TaskType::FollowUp,
        'priority' => \App\Enums\TaskPriority::Medium,
        'status' => TaskStatus::Pending,
        'created_by' => $otherUser->id,
        'due_at' => now()->addDay(),
    ]);

    Artisan::call('tasks:send-reminders');

    Notification::assertNothingSentTo($this->user);
});

test('digest command sends summary email notification when tasks exist', function () {
    Notification::fake();

    Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->subDay(),
    ]);

    Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->setTime(15, 0),
    ]);

    Artisan::call('tasks:send-digest');

    Notification::assertSentTo($this->user, TaskDailyDigestNotification::class, function (TaskDailyDigestNotification $notification) {
        return $notification->organization->is($this->org)
            && $notification->overdueTasks->count() === 1
            && $notification->dueTodayTasks->count() === 1;
    });
});

test('digest command skips sending when feature is disabled', function () {
    Notification::fake();

    config()->set('tasks.notifications.daily_digest_enabled', false);

    Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->subDay(),
    ]);

    Artisan::call('tasks:send-digest');

    Notification::assertNothingSent();
});
