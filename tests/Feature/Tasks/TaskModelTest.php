<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Contact;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('tasks table has required columns', function () {
    expect(Schema::hasTable('tasks'))->toBeTrue()
        ->and(Schema::hasColumns('tasks', [
            'id',
            'organization_id',
            'contact_id',
            'title',
            'due_at',
            'type',
            'priority',
            'notes',
            'status',
            'completed_at',
            'deleted_at',
            'created_by',
            'created_at',
            'updated_at',
        ]))->toBeTrue();
});

test('global organization scope returns only tenant tasks', function () {
    $taskInCurrentOrg = Task::create([
        'organization_id' => $this->org->id,
        'title' => 'Current org task',
        'type' => TaskType::FollowUp,
        'priority' => TaskPriority::Medium,
        'status' => TaskStatus::Pending,
        'created_by' => $this->user->id,
    ]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $taskInOtherOrg = Task::withoutGlobalScope('organization')->create([
        'organization_id' => $otherOrg->id,
        'title' => 'Other org task',
        'type' => TaskType::Call,
        'priority' => TaskPriority::High,
        'status' => TaskStatus::Pending,
        'created_by' => $otherUser->id,
    ]);

    expect(Task::all())->toHaveCount(1)
        ->and(Task::first()->is($taskInCurrentOrg))->toBeTrue();

    Filament::setTenant($otherOrg);

    expect(Task::all())->toHaveCount(1)
        ->and(Task::first()->is($taskInOtherOrg))->toBeTrue();
});

test('task relationships return linked models', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $task = Task::create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'title' => 'Call back lead',
        'type' => TaskType::Call,
        'priority' => TaskPriority::High,
        'status' => TaskStatus::Pending,
        'created_by' => $this->user->id,
    ]);

    expect($task->organization->is($this->org))->toBeTrue()
        ->and($task->contact?->is($contact))->toBeTrue()
        ->and($task->creator->is($this->user))->toBeTrue();
});

test('status defaults to pending when omitted', function () {
    $task = Task::create([
        'organization_id' => $this->org->id,
        'title' => 'Default status task',
        'type' => TaskType::FollowUp,
        'priority' => TaskPriority::Medium,
        'created_by' => $this->user->id,
    ]);

    expect($task->status)->toBe(TaskStatus::Pending);
});

test('invalid enum value is rejected by enum casting', function () {
    DB::table('tasks')->insert([
        'organization_id' => $this->org->id,
        'title' => 'Invalid enum task',
        'type' => TaskType::FollowUp->value,
        'priority' => 'critical',
        'status' => TaskStatus::Pending->value,
        'created_by' => $this->user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    Task::query()->first()->priority;
})->throws(ValueError::class);

test('scope helpers return expected records', function () {
    $overdue = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->subDay(),
    ]);

    $thisWeek = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
    ]);

    $done = Task::factory()->done()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'due_at' => now()->subDays(2),
    ]);

    expect(Task::overdue()->pluck('id')->all())
        ->toContain($overdue->id)
        ->not->toContain($done->id)
        ->and(Task::dueThisWeek()->pluck('id')->all())
        ->toContain($thisWeek->id);
});
