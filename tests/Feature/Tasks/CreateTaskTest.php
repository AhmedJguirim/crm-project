<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Contact;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('user can create task with all form fields filled', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(CreateTask::class)
        ->fillForm([
            'title' => 'Follow up with ACME',
            'due_at' => now()->addDays(2)->setTime(10, 30)->toDateTimeString(),
            'contact_id' => $contact->id,
            'type' => TaskType::Call,
            'priority' => TaskPriority::High,
            'notes' => 'Call regarding proposal revision.',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(TaskResource::getUrl('index'));

    $task = Task::query()->latest('id')->first();

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Follow up with ACME')
        ->and($task->contact_id)->toBe($contact->id)
        ->and($task->type)->toBe(TaskType::Call)
        ->and($task->priority)->toBe(TaskPriority::High)
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->organization_id)->toBe($this->org->id)
        ->and($task->created_by)->toBe($this->user->id);
});

test('minimal task creation applies smart defaults', function () {
    Livewire::test(CreateTask::class)
        ->fillForm([
            'title' => 'Default values task',
            'contact_id' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $task = Task::query()->latest('id')->first();

    expect($task->title)->toBe('Default values task')
        ->and($task->type)->toBe(TaskType::FollowUp)
        ->and($task->priority)->toBe(TaskPriority::Medium)
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->contact_id)->toBeNull()
        ->and($task->due_at?->isSameDay(now()->addDays(3)))->toBeTrue();
});

test('title is required', function () {
    Livewire::test(CreateTask::class)
        ->fillForm([
            'title' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['title' => 'required']);
});

test('contact from another organization is rejected', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();
    $otherContact = Contact::factory()->create(['organization_id' => $otherOrg->id]);

    Livewire::test(CreateTask::class)
        ->fillForm([
            'title' => 'Invalid contact assignment',
            'contact_id' => $otherContact->id,
        ])
        ->call('create')
        ->assertHasFormErrors(['contact_id']);
});

test('newly created task appears in pending list', function () {
    Livewire::test(CreateTask::class)
        ->fillForm([
            'title' => 'Appears in list',
            'type' => TaskType::Email,
            'priority' => TaskPriority::Low,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Task::query()->where('title', 'Appears in list')->exists())->toBeTrue();
});
