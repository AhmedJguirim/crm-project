<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Martin']);
    $this->org = $this->alice->personalOrganization();
    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Stone']);
    $this->vera = User::factory()->onboardingCompleted()->create(['name' => 'Vera Viewer']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);
    $this->org->members()->attach($this->vera, ['role' => OrganizationRole::Viewer->value]);
    $this->eve = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Eve Other']);

    $this->actingAs($this->bob);
    Filament::setTenant($this->org);

    $this->makeTask = fn (string $title, ?User $owner, TaskStatus $status = TaskStatus::Pending, ?Organization $org = null): Task => Task::factory()->create([
        'organization_id' => ($org ?? $this->org)->id,
        'created_by' => $this->alice->id,
        'owner_id' => $owner?->id,
        'title' => $title,
        'status' => $status,
        'due_at' => now()->addDay(),
    ]);

    $this->t1 = ($this->makeTask)('T1', $this->alice);
    $this->t2 = ($this->makeTask)('T2', null, TaskStatus::Done);
});

test('tasks, done ones included, are assigned and unassigned in one step', function () {
    Livewire::test(ListTasks::class)
        ->removeTableFilter('status')
        ->callTableBulkAction('assignTasks', [$this->t1, $this->t2], ['owner_id' => $this->vera->id])
        ->assertNotified('Assigned 2 tasks to Vera Viewer')
        ->callTableBulkAction('assignTasks', [$this->t1, $this->t2], ['owner_id' => null])
        ->assertNotified('Unassigned 2 tasks');

    expect($this->t1->fresh()->owner_id)->toBeNull()
        ->and($this->t2->fresh()->owner_id)->toBeNull()
        ->and($this->t2->fresh()->status)->toBe(TaskStatus::Done);
});

test('done tasks get the new assignee too', function () {
    Livewire::test(ListTasks::class)
        ->removeTableFilter('status')
        ->callTableBulkAction('assignTasks', [$this->t1, $this->t2], ['owner_id' => $this->vera->id]);

    expect($this->t1->fresh()->owner_id)->toBe($this->vera->id)
        ->and($this->t2->fresh()->owner_id)->toBe($this->vera->id)
        ->and($this->t2->fresh()->status)->toBe(TaskStatus::Done);
});

test('one task is worded in the singular', function () {
    Livewire::test(ListTasks::class)
        ->callTableBulkAction('assignTasks', [$this->t1], ['owner_id' => $this->bob->id])
        ->assertNotified('Assigned 1 task to Bob Stone')
        ->callTableBulkAction('assignTasks', [$this->t1], ['owner_id' => null])
        ->assertNotified('Unassigned 1 task');
});

test('a forged assignee outside the members is refused', function (string $forged) {
    $value = match ($forged) {
        'other organization' => $this->eve->id,
        'letters' => 'abc',
        'unknown id' => 99999999,
    };

    Livewire::test(ListTasks::class)
        ->callTableBulkAction('assignTasks', [$this->t1], ['owner_id' => $value])
        ->assertHasTableBulkActionErrors(['owner_id' => 'in']);

    expect($this->t1->fresh()->owner_id)->toBe($this->alice->id);
})->with(['other organization', 'letters', 'unknown id']);

test('a former member cannot be assigned', function () {
    $carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);
    $this->org->members()->attach($carl, ['role' => OrganizationRole::Member->value]);
    $this->org->members()->detach($carl->id);
    ($this->makeTask)('Legacy', $carl);

    Livewire::test(ListTasks::class)
        ->callTableBulkAction('assignTasks', [$this->t1], ['owner_id' => $carl->id])
        ->assertHasTableBulkActionErrors(['owner_id' => 'in']);

    expect($this->t1->fresh()->owner_id)->toBe($this->alice->id);
});

test('who sees the bulk action follows the edit rule', function (string $user, bool $visible) {
    $this->actingAs($this->{$user});
    Filament::setTenant($this->org);

    $page = Livewire::test(ListTasks::class);

    $visible ? $page->assertTableBulkActionVisible('assignTasks') : $page->assertTableBulkActionHidden('assignTasks');
})->with([
    'owner' => ['alice', true],
    'member' => ['bob', true],
    'viewer' => ['vera', false],
]);

test('a viewer cannot run the bulk action through a forged call', function () {
    $this->actingAs($this->vera);
    Filament::setTenant($this->org);

    Livewire::test(ListTasks::class)
        ->call('mountTableBulkAction', 'assignTasks', [(string) $this->t1->id])
        ->assertActionNotMounted()
        ->call('callMountedTableBulkAction', ['owner_id' => $this->vera->id]);

    expect($this->t1->fresh()->owner_id)->toBe($this->alice->id);
});

test('tasks of another organization are never touched', function () {
    $spy = ($this->makeTask)('Spy', $this->eve, TaskStatus::Pending, $this->eve->personalOrganization());

    Livewire::test(ListTasks::class)
        ->assertCanNotSeeTableRecords([$spy])
        ->callTableBulkAction('assignTasks', Task::query()->get(), ['owner_id' => $this->vera->id]);

    expect($spy->fresh()->owner_id)->toBe($this->eve->id);

    Livewire::test(ListTasks::class)
        ->callTableBulkAction('assignTasks', [$spy], ['owner_id' => $this->vera->id]);

    expect($spy->fresh()->owner_id)->toBe($this->eve->id);
});
