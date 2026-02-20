<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Models\Contact;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-02-20 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('tasks list page loads successfully', function () {
    Livewire::test(ListTasks::class)->assertSuccessful();
});

test('default view shows pending tasks sorted by due date with overdue first', function () {
    $overdue = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Overdue task',
        'status' => TaskStatus::Pending,
        'due_at' => Carbon::now()->subDay(),
    ]);

    $upcoming = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Upcoming task',
        'status' => TaskStatus::Pending,
        'due_at' => Carbon::now()->addDay(),
    ]);

    $done = Task::factory()->done()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Completed task',
        'due_at' => Carbon::now()->subDays(2),
    ]);

    Livewire::test(ListTasks::class)
        ->assertCanSeeTableRecords([$overdue, $upcoming], inOrder: true)
        ->assertCanNotSeeTableRecords([$done]);
});

test('today and overdue filters work correctly', function () {
    $today = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => Carbon::now()->setTime(14, 0),
    ]);

    $overdue = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => Carbon::now()->subDay(),
    ]);

    $future = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => Carbon::now()->addDays(4),
    ]);

    Livewire::test(ListTasks::class)
        ->filterTable('today')
        ->assertCanSeeTableRecords([$today])
        ->assertCountTableRecords(1);

    Livewire::test(ListTasks::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCountTableRecords(1);
});

test('done filter only displays completed tasks', function () {
    $pending = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
    ]);

    $done = Task::factory()->done()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListTasks::class)
        ->filterTable('status', TaskStatus::Done->value)
        ->assertCanSeeTableRecords([$done])
        ->assertCanNotSeeTableRecords([$pending]);
});

test('list is isolated by current organization', function () {
    $ownTask = Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $otherTask = Task::withoutGlobalScope('organization')->create([
        'organization_id' => $otherOrg->id,
        'title' => 'Other org task',
        'type' => TaskType::FollowUp,
        'priority' => TaskPriority::Medium,
        'status' => TaskStatus::Pending,
        'created_by' => $otherUser->id,
    ]);

    Livewire::test(ListTasks::class)
        ->assertCanSeeTableRecords([$ownTask])
        ->assertCanNotSeeTableRecords([$otherTask]);
});

test('empty state is shown with create action when there are no tasks', function () {
    Livewire::test(ListTasks::class)
        ->assertCountTableRecords(0)
        ->assertActionExists('create');
});

test('contact column renders link to contact details when task has linked contact', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'contact_id' => $contact->id,
        'title' => 'Task with contact',
    ]);

    Livewire::test(ListTasks::class)
        ->assertSee(ContactResource::getUrl('view', ['record' => $contact]));
});

test('table includes required columns', function () {
    Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListTasks::class)
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('contact.name')
        ->assertTableColumnExists('due_at')
        ->assertTableColumnExists('type')
        ->assertTableColumnExists('priority')
        ->assertTableColumnExists('status');
});
