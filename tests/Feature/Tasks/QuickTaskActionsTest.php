<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Livewire\Filament\QuickTaskFloatingButton;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
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

dataset('locked contact quick task entry points', [
    'contact page' => 'contact',
    'deal page' => 'deal',
    'contacts table row' => 'table',
]);

function mountLockedContactQuickTask(string $entryPoint, Contact $contact, Deal $deal): Testable
{
    if ($entryPoint === 'contact') {
        return Livewire::test(ViewContact::class, ['record' => $contact->id])->mountAction('quickTask');
    }

    if ($entryPoint === 'deal') {
        return Livewire::test(ViewDeal::class, ['record' => $deal->id])->mountAction('quickTask');
    }

    return Livewire::test(ListContacts::class)->mountTableAction('addTask', $contact);
}

function lockedContactQuickTaskFixture(object $test): array
{
    Carbon::setTestNow('2026-10-09 12:00:00');

    $contact = Contact::factory()->create(['organization_id' => $test->org->id, 'name' => 'Jane Doe']);
    $deal = Deal::factory()->create([
        'organization_id' => $test->org->id,
        'contact_id' => $contact->id,
        'created_by' => $test->user->id,
    ]);

    return [$contact, $deal];
}

test('locked contact quick task forms open with follow-up and a due date in 3 days', function (string $entryPoint) {
    [$contact, $deal] = lockedContactQuickTaskFixture($this);

    $hasDefaults = function (array $state) use ($contact): array {
        expect($state['type'])->toBeIn([TaskType::FollowUp, TaskType::FollowUp->value])
            ->and($state['due_at'])->not->toBeNull()
            ->and(Carbon::parse($state['due_at'])->toDateTimeString())->toBe('2026-10-12 12:00:00')
            ->and((int) $state['contact_id'])->toBe($contact->id);

        return [];
    };

    $component = mountLockedContactQuickTask($entryPoint, $contact, $deal);

    $entryPoint === 'table'
        ? $component->assertTableActionDataSet($hasDefaults)
        : $component->assertActionDataSet($hasDefaults);
})->with('locked contact quick task entry points');

test('locked contact quick task forms create a task from a title alone', function (string $entryPoint) {
    [$contact, $deal] = lockedContactQuickTaskFixture($this);

    mountLockedContactQuickTask($entryPoint, $contact, $deal)
        ->fillForm(['title' => 'Call back'])
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertNotified('Task created successfully');

    $task = Task::query()->where('title', 'Call back')->first();

    expect($task)->not->toBeNull()
        ->and($task->type)->toBe(TaskType::FollowUp)
        ->and($task->due_at->toDateTimeString())->toBe('2026-10-12 12:00:00')
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($task->contact_id)->toBe($contact->id);
})->with('locked contact quick task entry points');

test('the deal page quick task starts with the contact, the deal and the defaults', function () {
    [$contact, $deal] = lockedContactQuickTaskFixture($this);

    $hasDeal = function (array $state) use ($contact, $deal): array {
        expect((int) $state['contact_id'])->toBe($contact->id)
            ->and($state['contact_name'])->toBe('Jane Doe')
            ->and((int) $state['deal_id'])->toBe($deal->id)
            ->and($state['type'])->toBeIn([TaskType::FollowUp, TaskType::FollowUp->value])
            ->and(Carbon::parse($state['due_at'])->toDateTimeString())->toBe('2026-10-12 12:00:00');

        return [];
    };

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->mountAction('quickTask')
        ->assertActionDataSet($hasDeal);
});

test('the deal page quick task creates a task linked to the deal', function () {
    [$contact, $deal] = lockedContactQuickTaskFixture($this);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->mountAction('quickTask')
        ->fillForm(['title' => 'Call back'])
        ->callMountedAction()
        ->assertHasNoErrors()
        ->assertNotified('Task created successfully');

    $tasks = Task::query()->where('title', 'Call back')->get();

    expect($tasks)->toHaveCount(1)
        ->and($tasks->first()->contact_id)->toBe($contact->id)
        ->and($tasks->first()->deal_id)->toBe($deal->id)
        ->and($tasks->first()->status)->toBe(TaskStatus::Pending)
        ->and($tasks->first()->due_at->toDateTimeString())->toBe('2026-10-12 12:00:00')
        ->and($tasks->first()->owner_id)->toBe($this->user->id);
});

test('the deal page quick task keeps its label and colour', function () {
    [, $deal] = lockedContactQuickTaskFixture($this);

    $action = Livewire::test(ViewDeal::class, ['record' => $deal->id])->instance()->getAction('quickTask');

    expect($action->getLabel())->toBe('Quick Task')
        ->and($action->getColor())->toBe('primary');
});

test('the deal page quick task is hidden for a deal without a live contact', function (string $case) {
    [$contact] = lockedContactQuickTaskFixture($this);

    $solo = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $case === 'no contact' ? null : $contact->id,
        'created_by' => $this->user->id,
        'title' => 'Solo',
    ]);

    if ($case === 'trashed contact') {
        $contact->delete();
    }

    Livewire::test(ViewDeal::class, ['record' => $solo->id])->assertActionHidden('quickTask');
})->with(['no contact', 'trashed contact']);

test('a viewer does not see the deal page quick task', function () {
    [, $deal] = lockedContactQuickTaskFixture($this);

    $viewer = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
    $this->actingAs($viewer);
    Filament::setTenant($this->org);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])->assertActionHidden('quickTask');
});
