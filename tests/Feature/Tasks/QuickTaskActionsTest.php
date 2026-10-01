<?php

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Livewire\Filament\QuickTaskFloatingButton;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('global floating quick task action creates task with minimal data', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(QuickTaskFloatingButton::class)
        ->mountAction('quickTask')
        ->set('mountedActions.0.data.title', 'Global quick follow-up')
        ->set('mountedActions.0.data.contact_id', $contact->id)
        ->set('mountedActions.0.data.deal_id', $deal->id)
        ->set('mountedActions.0.data.type', TaskType::FollowUp->value)
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertNotified();

    $task = Task::query()->latest('id')->first();

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Global quick follow-up')
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->organization_id)->toBe($this->org->id)
        ->and($task->created_by)->toBe($this->user->id)
        ->and($task->contact_id)->toBe($contact->id)
        ->and($task->deal_id)->toBe($deal->id)
        ->and($task->type)->toBe(TaskType::FollowUp)
        ->and($task->due_at?->isSameDay(now()->addDays(3)))->toBeTrue();
});

test('contact detail quick task action creates task locked to the viewed contact', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->assertActionExists('quickTask')
        ->callAction('quickTask', [
            'title' => 'Contact detail quick task',
            'contact_id' => $contact->id,
            'deal_id' => $deal->id,
            'type' => TaskType::Call,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $task = Task::query()->latest('id')->first();

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Contact detail quick task')
        ->and($task->contact_id)->toBe($contact->id)
        ->and($task->deal_id)->toBe($deal->id)
        ->and($task->status)->toBe(TaskStatus::Pending);
});

test('contacts table add task action creates task locked to row contact', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListContacts::class)
        ->assertTableActionExists('addTask')
        ->callTableAction('addTask', $contact, [
            'title' => 'Row action quick task',
            'contact_id' => $contact->id,
            'deal_id' => $deal->id,
            'type' => TaskType::Email,
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $task = Task::query()->latest('id')->first();

    expect($task)->not->toBeNull()
        ->and($task->title)->toBe('Row action quick task')
        ->and($task->contact_id)->toBe($contact->id)
        ->and($task->deal_id)->toBe($deal->id)
        ->and($task->status)->toBe(TaskStatus::Pending);
});

test('list tasks mark done row action marks pending task as done', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'completed_at' => null,
    ]);

    Livewire::test(ListTasks::class)
        ->assertTableActionExists('markDone')
        ->callTableAction('markDone', $task)
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at)->not->toBeNull();
});

test('list tasks snooze preset updates due date', function () {
    Carbon::setTestNow('2026-02-21 09:00:00');

    $task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => Carbon::parse('2026-02-21 12:00:00'),
    ]);

    Livewire::test(ListTasks::class)
        ->callTableAction('snooze3Days', $task)
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $task->refresh();

    expect($task->due_at?->format('Y-m-d H:i:s'))->toBe('2026-02-24 12:00:00');
});

test('list tasks custom snooze updates due date', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
    ]);

    Livewire::test(ListTasks::class)
        ->callTableAction('snoozeCustom', $task, [
            'due_at' => '2030-03-02 11:30:00',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $task->refresh();

    expect($task->due_at?->format('Y-m-d H:i:s'))->toBe('2030-03-02 11:30:00');
});

test('list task delete action soft deletes the task', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListTasks::class)
        ->callTableAction('delete', $task)
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    expect(Task::query()->whereKey($task->id)->exists())->toBeFalse();
    expect(Task::withTrashed()->whereKey($task->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

test('detail delete action soft deletes and redirects to list', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(EditTask::class, ['record' => $task->id])
        ->callAction('delete')
        ->assertHasNoActionErrors()
        ->assertNotified()
        ->assertRedirect();

    expect(Task::query()->whereKey($task->id)->exists())->toBeFalse();
    expect(Task::withTrashed()->whereKey($task->id)->whereNotNull('deleted_at')->exists())->toBeTrue();
});

test('edit task mark done header action marks task as done', function () {
    $task = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'completed_at' => null,
    ]);

    Livewire::test(EditTask::class, ['record' => $task->id])
        ->assertActionExists('markDone')
        ->callAction('markDone')
        ->assertHasNoActionErrors()
        ->assertNotified();

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at)->not->toBeNull();
});
