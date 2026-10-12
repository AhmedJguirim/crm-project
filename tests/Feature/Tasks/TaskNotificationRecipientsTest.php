<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskDailyDigestNotification;
use App\Notifications\TaskDueSoonNotification;
use App\Notifications\TaskOverdueNotification;
use App\Services\Tasks\TaskRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config(['tasks.notifications.send_email' => false, 'tasks.notifications.daily_digest_enabled' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-02-21 08:00:00', 'UTC'));

    $this->owner = User::factory()->onboardingCompleted()->create();
    $this->alice = User::factory()->onboardingCompleted()->create();
    $this->victor = User::factory()->onboardingCompleted()->create();
    $this->org = Organization::factory()->timezone('UTC')->create(['created_by' => $this->owner->id]);
    $this->org->members()->attach($this->owner, ['role' => OrganizationRole::Owner->value]);
    $this->org->members()->attach($this->alice, ['role' => OrganizationRole::Member->value]);
    $this->org->members()->attach($this->victor, ['role' => OrganizationRole::Viewer->value]);

    $this->taskFor = fn (string $title, ?User $assignee, string $dueAtUtc, ?Organization $organization = null): Task => Task::factory()->create([
        'organization_id' => ($organization ?? $this->org)->id,
        'created_by' => $this->owner->id,
        'owner_id' => $assignee?->id,
        'title' => $title,
        'status' => TaskStatus::Pending,
        'due_at' => $dueAtUtc,
    ]);

    $this->digestOf = function (User $user): ?TaskDailyDigestNotification {
        $sent = Notification::sent($user, TaskDailyDigestNotification::class);

        return $sent->first();
    };

    $this->overdueTitlesFor = fn (User $user): array => Notification::sent($user, TaskOverdueNotification::class)
        ->map(fn (TaskOverdueNotification $notification): string => $notification->task->title)->all();
});

describe('reminders', function () {
    it('notifies only the assignee of an overdue task', function () {
        Notification::fake();
        ($this->taskFor)('Call Bob', $this->alice, '2026-02-20 08:00:00');

        Artisan::call('tasks:send-reminders', ['--all' => true]);

        expect(($this->overdueTitlesFor)($this->alice))->toBe(['Call Bob'])
            ->and(($this->overdueTitlesFor)($this->owner))->toBe([])
            ->and(($this->overdueTitlesFor)($this->victor))->toBe([]);
    });

    it('notifies only the assignee of a task due tomorrow', function () {
        Notification::fake();
        ($this->taskFor)('Send quote', $this->victor, '2026-02-22 10:00:00');

        Artisan::call('tasks:send-reminders', ['--all' => true]);

        Notification::assertSentTo($this->victor, TaskDueSoonNotification::class, fn (TaskDueSoonNotification $notification): bool => $notification->task->title === 'Send quote');
        Notification::assertNotSentTo($this->owner, TaskDueSoonNotification::class);
        Notification::assertNotSentTo($this->alice, TaskDueSoonNotification::class);
    });

    it('notifies everybody about an unassigned task', function () {
        Notification::fake();
        ($this->taskFor)('Renew contract', null, '2026-02-20 08:00:00');

        Artisan::call('tasks:send-reminders', ['--all' => true]);

        foreach ([$this->owner, $this->alice, $this->victor] as $member) {
            expect(($this->overdueTitlesFor)($member))->toBe(['Renew contract']);
        }
    });

    it('notifies the other members, never a former member, when the assignee left', function () {
        Notification::fake();
        ($this->taskFor)('Old lead', $this->alice, '2026-02-20 08:00:00');
        $this->org->members()->detach($this->alice);

        Artisan::call('tasks:send-reminders', ['--all' => true]);

        expect(($this->overdueTitlesFor)($this->owner))->toBe(['Old lead'])
            ->and(($this->overdueTitlesFor)($this->victor))->toBe(['Old lead'])
            ->and(($this->overdueTitlesFor)($this->alice))->toBe([]);
    });

    it('treats an assignee who belongs to another organization only as not a member', function () {
        Notification::fake();
        $bob = User::factory()->onboardingCompleted()->create();
        $globex = Organization::factory()->timezone('UTC')->create(['created_by' => $bob->id]);
        $globex->members()->attach($bob, ['role' => OrganizationRole::Owner->value]);
        ($this->taskFor)('Cross', $bob, '2026-02-20 08:00:00');

        Artisan::call('tasks:send-reminders', ['--all' => true]);

        expect(($this->overdueTitlesFor)($bob))->toBe([])
            ->and(($this->overdueTitlesFor)($this->owner))->toBe(['Cross'])
            ->and(($this->overdueTitlesFor)($this->alice))->toBe(['Cross'])
            ->and(($this->overdueTitlesFor)($this->victor))->toBe(['Cross']);
    });

    it('sends the reminder to the new assignee on the next run after a reassignment', function () {
        $task = ($this->taskFor)('Call Bob', $this->alice, '2026-02-20 08:00:00');
        Artisan::call('tasks:send-reminders', ['--all' => true]);

        $task->update(['owner_id' => $this->victor->id]);
        Artisan::call('tasks:send-reminders', ['--all' => true]);

        $countFor = fn (User $user): int => DatabaseNotification::query()
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', TaskOverdueNotification::class)
            ->count();

        expect($countFor($this->victor))->toBe(1)->and($countFor($this->alice))->toBe(1)->and($countFor($this->owner))->toBe(0);
    });
});

describe('the daily digest', function () {
    it('lists each member their own tasks plus the unassigned ones', function () {
        Notification::fake();
        ($this->taskFor)('Alice late', $this->alice, '2026-02-20 08:00:00');
        ($this->taskFor)('Owner today', $this->owner, '2026-02-21 15:00:00');
        ($this->taskFor)('Shared', null, '2026-02-21 16:00:00');

        Artisan::call('tasks:send-digest', ['--all' => true]);

        $titles = fn (User $user): array => [
            ($this->digestOf)($user)->overdueTasks->pluck('title')->all(),
            ($this->digestOf)($user)->dueTodayTasks->pluck('title')->all(),
        ];

        expect($titles($this->alice))->toBe([['Alice late'], ['Shared']])
            ->and($titles($this->owner))->toBe([[], ['Owner today', 'Shared']])
            ->and($titles($this->victor))->toBe([[], ['Shared']]);
    });

    it('sends no digest to a member with nothing to do', function () {
        Notification::fake();
        ($this->taskFor)('Alice late', $this->alice, '2026-02-20 08:00:00');

        Artisan::call('tasks:send-digest', ['--all' => true]);

        Notification::assertSentTo($this->alice, TaskDailyDigestNotification::class);
        Notification::assertNotSentTo($this->owner, TaskDailyDigestNotification::class);
        Notification::assertNotSentTo($this->victor, TaskDailyDigestNotification::class);
    });

    it('sends the digest of a task whose assignee left to the remaining members only', function () {
        Notification::fake();
        ($this->taskFor)('Orphan', $this->alice, '2026-02-20 08:00:00');
        $this->org->members()->detach($this->alice);

        Artisan::call('tasks:send-digest', ['--all' => true]);

        expect(($this->digestOf)($this->owner)->overdueTasks->pluck('title')->all())->toBe(['Orphan']);
        Notification::assertNotSentTo($this->alice, TaskDailyDigestNotification::class);
    });

    it('does not read another organization\'s tasks', function () {
        Notification::fake();
        $globex = Organization::factory()->timezone('UTC')->create(['created_by' => $this->owner->id]);
        ($this->taskFor)('Foreign', null, '2026-02-20 08:00:00', $globex);

        Artisan::call('tasks:send-digest', ['--all' => true]);

        Notification::assertNothingSent();
    });
});

describe('TaskRecipients', function () {
    it('applies the recipient rules', function () {
        $members = new Collection([$this->owner, $this->alice]);
        $unassigned = Task::factory()->make(['owner_id' => null]);
        $ofAlice = Task::factory()->make(['owner_id' => $this->alice->id]);
        $ofStranger = Task::factory()->make(['owner_id' => $this->victor->id]);

        expect(TaskRecipients::includes($unassigned, $this->owner, $members))->toBeTrue()
            ->and(TaskRecipients::includes($ofAlice, $this->alice, $members))->toBeTrue()
            ->and(TaskRecipients::includes($ofAlice, $this->owner, $members))->toBeFalse()
            ->and(TaskRecipients::includes($ofStranger, $this->owner, $members))->toBeTrue()
            ->and(TaskRecipients::for($ofAlice, $members)->pluck('id')->all())->toBe([$this->alice->id]);
    });
});
