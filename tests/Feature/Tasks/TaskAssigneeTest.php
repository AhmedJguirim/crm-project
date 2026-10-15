<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Filament\Resources\Tasks\Pages\EditTask;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Livewire\Filament\QuickTaskFloatingButton;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\Owners\OwnerOptions;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Martin']);
    $this->org = $this->alice->personalOrganization();
    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Stone']);
    $this->vera = User::factory()->onboardingCompleted()->create(['name' => 'Vera Viewer']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);
    $this->org->members()->attach($this->vera, ['role' => OrganizationRole::Viewer->value]);

    $this->eve = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Eve Other']);

    $this->actingAs($this->alice);
    Filament::setTenant($this->org);

    $this->makeTask = fn (string $title, ?User $owner, ?Organization $org = null): Task => Task::factory()->create([
        'organization_id' => ($org ?? $this->org)->id,
        'created_by' => $this->alice->id,
        'owner_id' => $owner?->id,
        'title' => $title,
        'status' => TaskStatus::Pending,
        'due_at' => now()->addDay(),
    ]);

    $this->formerMember = function (string $taskTitle = 'Legacy call'): array {
        $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);
        $this->org->members()->attach($carl, ['role' => OrganizationRole::Member->value]);
        $task = ($this->makeTask)($taskTitle, $carl);
        $this->org->members()->detach($carl->id);

        return [$carl, $task->fresh()];
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a new task is assigned to its creator by default', function () {
    Livewire::test(CreateTask::class)
        ->assertFormSet(['owner_id' => $this->alice->id])
        ->fillForm(['title' => 'Send proposal'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Task::query()->where('title', 'Send proposal')->firstOrFail()->owner_id)->toBe($this->alice->id);
});

test('the assignee options are the current members by name', function () {
    $members = [
        $this->alice->id => 'Alice Martin',
        $this->bob->id => 'Bob Stone',
        $this->vera->id => 'Vera Viewer',
    ];

    expect(OwnerOptions::forForm(null))->toBe($members)
        ->and(OwnerOptions::forFilter(Task::class))->toBe($members);
});

test('a task can be assigned to another member or to nobody', function () {
    Livewire::test(CreateTask::class)
        ->fillForm(['title' => "Bob's task", 'owner_id' => $this->bob->id])
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateTask::class)
        ->fillForm(['title' => "Nobody's task", 'owner_id' => null])
        ->call('create')
        ->assertHasNoFormErrors();

    $nobody = Task::query()->where('title', "Nobody's task")->firstOrFail();

    expect(Task::query()->where('title', "Bob's task")->firstOrFail()->owner_id)->toBe($this->bob->id)
        ->and($nobody->owner_id)->toBeNull();

    Livewire::test(ListTasks::class)
        ->assertTableColumnStateSet('owner_id', null, $nobody)
        ->assertSee('Unassigned');
});

test('a forged assignee is refused', function (string|int $forged) {
    $forged = $forged === 'eve' ? $this->eve->id : $forged;

    Livewire::test(CreateTask::class)
        ->fillForm(['title' => 'Forged', 'owner_id' => $forged])
        ->call('create')
        ->assertHasFormErrors(['owner_id' => 'in']);

    expect(Task::query()->where('title', 'Forged')->exists())->toBeFalse();
})->with(['another organization member' => 'eve', 'a string' => 'abc', 'an unknown id' => 999999]);

test('a removed member stays assigned and is marked', function () {
    [$carl, $legacy] = ($this->formerMember)();

    expect($legacy->owner_id)->toBe($carl->id);

    Livewire::test(ListTasks::class)
        ->assertTableColumnStateSet('owner_id', 'Carl Weber (no longer a member)', $legacy);

    expect(OwnerOptions::forForm($legacy))->toHaveKey($carl->id, 'Carl Weber (no longer a member)')
        ->and(OwnerOptions::forForm(null))->not->toHaveKey($carl->id);

    Livewire::test(EditTask::class, ['record' => $legacy->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($legacy->fresh()->owner_id)->toBe($carl->id);
});

test('a former member cannot be assigned another task', function () {
    [$carl] = ($this->formerMember)();
    $mine = ($this->makeTask)('Mine', $this->alice);

    Livewire::test(EditTask::class, ['record' => $mine->getRouteKey()])
        ->fillForm(['owner_id' => $carl->id])
        ->call('save')
        ->assertHasFormErrors(['owner_id' => 'in']);

    expect($mine->fresh()->owner_id)->toBe($this->alice->id);
});

test('a member can reassign a task and a viewer cannot edit it', function () {
    $mine = ($this->makeTask)('Mine', $this->alice);

    $this->actingAs($this->bob);
    Filament::setTenant($this->org);

    Livewire::test(EditTask::class, ['record' => $mine->getRouteKey()])
        ->fillForm(['owner_id' => $this->vera->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($mine->fresh()->owner_id)->toBe($this->vera->id);

    $this->actingAs($this->vera);
    Filament::setTenant($this->org);

    $this->get(TaskResource::getUrl('edit', ['record' => $mine]))->assertForbidden();
});

test('quick tasks are assigned to their creator', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->alice->id,
        'position' => '1000.0000000000',
    ]);

    Livewire::test(QuickTaskFloatingButton::class)
        ->mountAction('quickTask')
        ->set('mountedActions.0.data.title', 'Quick one')
        ->set('mountedActions.0.data.contact_id', $contact->id)
        ->set('mountedActions.0.data.type', TaskType::FollowUp->value)
        ->callMountedAction()
        ->assertHasNoErrors();

    Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $deal->id])
        ->call('mountAction', 'addTask', [], ['recordKey' => (string) $deal->id])
        ->fillForm(['title' => 'Drawer one'])
        ->call('callMountedAction')
        ->assertHasNoFormErrors();

    expect(Task::query()->whereIn('title', ['Quick one', 'Drawer one'])->pluck('owner_id')->all())
        ->toBe([$this->alice->id, $this->alice->id]);
});

test('the my tasks tab lists only the tasks assigned to me', function () {
    $mine = ($this->makeTask)('Mine', $this->alice);
    $his = ($this->makeTask)('His', $this->bob);
    $none = ($this->makeTask)('None', null);

    Livewire::test(ListTasks::class)
        ->set('activeTab', 'mine')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$his, $none])
        ->set('activeTab', 'all')
        ->assertCanSeeTableRecords([$mine, $his, $none]);
});

test('the assigned to filter narrows the table and lists a former member only for the model they hold', function () {
    $mine = ($this->makeTask)('Mine', $this->alice);
    $his = ($this->makeTask)('His', $this->bob);

    Livewire::test(ListTasks::class)
        ->filterTable('owner_id', $this->bob->id)
        ->assertCanSeeTableRecords([$his])
        ->assertCanNotSeeTableRecords([$mine]);

    [$carl] = ($this->formerMember)();

    expect(OwnerOptions::forFilter(Task::class))->toHaveKey($carl->id, 'Carl Weber (no longer a member)')
        ->and(OwnerOptions::forFilter(Deal::class))->not->toHaveKey($carl->id);
});

test('tasks of another organization never show up', function () {
    $secret = ($this->makeTask)('Globex secret', $this->eve, $this->eve->personalOrganization());
    $mine = ($this->makeTask)('Mine', $this->alice);

    Livewire::test(ListTasks::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$secret])
        ->set('activeTab', 'mine')
        ->assertCanNotSeeTableRecords([$secret]);

    expect(OwnerOptions::forForm(null))->not->toHaveKey($this->eve->id)
        ->and(OwnerOptions::forFilter(Task::class))->not->toHaveKey($this->eve->id);
});

test('deleting the assignee account empties the assignee and keeps the task', function () {
    $his = ($this->makeTask)('His', $this->bob);

    $this->bob->delete();

    expect(Task::query()->find($his->id))->not->toBeNull()
        ->and(Task::query()->find($his->id)->owner_id)->toBeNull();
});

test('the tasks table reads owner memberships once whatever the number of assignees', function () {
    $countMembershipQueries = function (): int {
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'organization_user')) {
                $queries++;
            }
        });

        Livewire::test(ListTasks::class);

        return $queries;
    };

    ($this->makeTask)('Task 0', $this->bob);
    Livewire::test(ListTasks::class);
    $withOne = $countMembershipQueries();

    foreach (range(1, 4) as $i) {
        $owner = User::factory()->onboardingCompleted()->create(['name' => "Owner {$i}"]);
        $this->org->members()->attach($owner, ['role' => OrganizationRole::Member->value]);
        ($this->makeTask)("Task {$i}a", $owner);
        ($this->makeTask)("Task {$i}b", $owner);
    }

    expect($countMembershipQueries())->toBe($withOne);
});
