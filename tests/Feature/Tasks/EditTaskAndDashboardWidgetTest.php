<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Widgets\MyTasksWidget;
use App\Models\Contact;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 10:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('task can be edited and saved from detail page', function () {
    $oldContact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $newContact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'contact_id' => $oldContact->id,
        'title' => 'Initial title',
        'type' => TaskType::FollowUp,
        'priority' => TaskPriority::Medium,
        'notes' => 'Initial notes',
    ]);

    Livewire::test(EditTask::class, ['record' => $task->id])
        ->fillForm([
            'title' => 'Updated title',
            'due_at' => '2026-02-24 14:30:00',
            'contact_id' => $newContact->id,
            'type' => TaskType::Call,
            'priority' => TaskPriority::High,
            'notes' => 'Updated notes',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $task->refresh();

    expect($task->title)->toBe('Updated title')
        ->and($task->contact_id)->toBe($newContact->id)
        ->and($task->type)->toBe(TaskType::Call)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->notes)->toBe('Updated notes')
        ->and($task->due_at?->format('Y-m-d H:i:s'))->toBe('2026-02-24 14:30:00');
});

test('title is required when editing a task', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(EditTask::class, ['record' => $task->id])
        ->fillForm([
            'title' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['title' => 'required']);
});

test('the dashboard lists my overdue tasks and not the later ones', function () {
    Task::factory()->count(3)->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'owner_id' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->subDay(),
    ]);

    Task::factory()->count(5)->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'owner_id' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDays(2),
    ]);

    Livewire::test(MyTasksWidget::class)->assertCountTableRecords(3);
});

test('a task marked done leaves the dashboard', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'owner_id' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->subDay(),
    ]);

    Livewire::test(MyTasksWidget::class)->assertCanSeeTableRecords([$task]);

    $task->update([
        'status' => TaskStatus::Done,
        'completed_at' => now(),
    ]);

    Livewire::test(MyTasksWidget::class)->assertCanNotSeeTableRecords([$task]);
});
