<?php

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Widgets\MyTasksWidget;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->me = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->me->personalOrganization();
    $this->org->update(['timezone' => 'Europe/Paris']);
    $this->colleague = User::factory()->onboardingCompleted()->create();
    $this->actingAs($this->me);
    Filament::setTenant($this->org->refresh());
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00:00', 'UTC'));

    $this->makeTeam = fn () => $this->org->members()->attach($this->colleague, ['role' => OrganizationRole::Member->value]);

    $this->widgetTask = fn (string $title, ?string $dueAt, ?User $owner = null, array $extra = []): Task => Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->me->id,
        'owner_id' => $owner?->id,
        'title' => $title,
        'due_at' => $dueAt,
        ...$extra,
    ]);
});

test('it lists my overdue tasks and my tasks due today, oldest first', function () {
    ($this->makeTeam)();
    ($this->widgetTask)('Later today', '2026-10-20 21:45:00', $this->me);
    ($this->widgetTask)('Old overdue', '2026-10-10 09:00:00', $this->me);
    ($this->widgetTask)('Overdue today', '2026-10-20 07:00:00', $this->me);

    Livewire::test(MyTasksWidget::class)
        ->assertSuccessful()
        ->assertSeeInOrder(['My tasks', 'Your overdue tasks and tasks due today.', 'Old overdue', 'Overdue today', 'Later today']);
});

test('tasks that are not listed', function (string $title, string $setup) {
    ($this->makeTeam)();
    $other = Organization::factory()->create();

    match ($setup) {
        'after today' => ($this->widgetTask)($title, '2026-10-20 22:30:00', $this->me),
        'someday' => ($this->widgetTask)($title, null, $this->me),
        'done' => ($this->widgetTask)($title, '2026-10-19 09:00:00', $this->me, ['status' => TaskStatus::Done, 'completed_at' => now()]),
        'deleted' => ($this->widgetTask)($title, '2026-10-19 09:00:00', $this->me)->delete(),
        'colleague' => ($this->widgetTask)($title, '2026-10-19 09:00:00', $this->colleague),
        'unassigned' => ($this->widgetTask)($title, '2026-10-19 09:00:00'),
        'other org' => Task::factory()->create(['organization_id' => $other->id, 'owner_id' => $this->me->id, 'title' => $title, 'due_at' => '2026-10-19 09:00:00', 'status' => TaskStatus::Pending]),
    };

    Livewire::test(MyTasksWidget::class)->assertDontSee($title);
})->with([
    'just after today' => ['Just after today', 'after today'],
    'someday' => ['Someday task', 'someday'],
    'finished' => ['Finished task', 'done'],
    'deleted' => ['Deleted task', 'deleted'],
    'colleague' => ['Colleague task', 'colleague'],
    'unassigned' => ['Unassigned task', 'unassigned'],
    'other organization' => ['Other org task', 'other org'],
]);

test('the last moment of the local day is listed, the next one is not', function () {
    ($this->makeTeam)();
    $in = ($this->widgetTask)('Edge in', '2026-10-20 21:59:59', $this->me);
    $out = ($this->widgetTask)('Edge out', '2026-10-20 22:00:00', $this->me);

    Livewire::test(MyTasksWidget::class)
        ->assertCanSeeTableRecords([$in])
        ->assertCanNotSeeTableRecords([$out]);
});

test('the unassigned count in a team', function () {
    ($this->makeTeam)();
    ($this->widgetTask)('Un 1', '2026-10-19 09:00:00');
    ($this->widgetTask)('Un 2', '2026-10-20 09:00:00');
    ($this->widgetTask)('Un 3', '2026-10-21 09:00:00');

    Livewire::test(MyTasksWidget::class)
        ->assertSee('2 unassigned tasks are overdue or due today.')
        ->assertDontSee('Un 1');

    Task::query()->where('title', 'Un 2')->first()->delete();

    Livewire::test(MyTasksWidget::class)
        ->assertSee('1 unassigned task is overdue or due today.')
        ->assertDontSee('2 unassigned tasks');

    Task::query()->where('title', 'Un 1')->first()->delete();

    Livewire::test(MyTasksWidget::class)->assertDontSee('unassigned');
});

test('the unassigned count ignores assigned tasks, other organizations and finished tasks', function () {
    ($this->makeTeam)();
    ($this->widgetTask)('Mine', '2026-10-19 09:00:00', $this->me);
    ($this->widgetTask)('Theirs', '2026-10-19 09:00:00', $this->colleague);
    Task::factory()->create(['organization_id' => Organization::factory()->create()->id, 'owner_id' => null, 'due_at' => '2026-10-19 09:00:00', 'status' => TaskStatus::Pending]);
    ($this->widgetTask)('Done unassigned', '2026-10-19 09:00:00', null, ['status' => TaskStatus::Done, 'completed_at' => now()]);

    Livewire::test(MyTasksWidget::class)->assertDontSee('unassigned');
});

test('a solo organization includes the unassigned tasks', function () {
    ($this->widgetTask)("Nobody's task", '2026-10-19 09:00:00');

    Livewire::test(MyTasksWidget::class)
        ->assertSee("Nobody's task")
        ->assertSee('Your overdue tasks and tasks due today, including unassigned ones.')
        ->assertDontSee('unassigned task');
});

test('a solo organization never lists the tasks of other organizations', function () {
    $other = Organization::factory()->create();
    Task::factory()->create(['organization_id' => $other->id, 'owner_id' => null, 'title' => 'Foreign nobody task', 'due_at' => '2026-10-19 09:00:00', 'status' => TaskStatus::Pending]);

    Livewire::test(MyTasksWidget::class)->assertDontSee('Foreign nobody task');
});

test('Mark Done from the dashboard', function () {
    ($this->makeTeam)();
    $task = ($this->widgetTask)('Call Ana', '2026-10-19 09:00:00', $this->me);

    Livewire::test(MyTasksWidget::class)
        ->assertCanSeeTableRecords([$task])
        ->callTableAction('markDone', $task)
        ->assertNotified('Task marked as done')
        ->assertCanNotSeeTableRecords([$task]);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Done)
        ->and($task->completed_at->toDateTimeString())->toBe('2026-10-20 08:00:00');
});

test('Snooze +1 day from the dashboard', function () {
    ($this->makeTeam)();
    $task = ($this->widgetTask)('Call Ana', '2026-10-19 09:00:00', $this->me);

    Livewire::test(MyTasksWidget::class)
        ->callTableAction('snooze1Day', $task)
        ->assertNotified('Task snoozed for 1 day')
        ->assertCanNotSeeTableRecords([$task]);

    expect($task->refresh()->due_at->toDateTimeString())->toBe('2026-10-21 08:00:00');
});

test('every role sees the widget, the viewer without actions', function (OrganizationRole $role, bool $actions) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    ($this->makeTeam)();
    $this->actingAs($user);
    Filament::setTenant($this->org);
    $task = ($this->widgetTask)('Role task', '2026-10-19 09:00:00', $user);

    $page = Livewire::test(MyTasksWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$task]);

    if ($actions) {
        $page->assertTableActionVisible('markDone', $task)->assertTableActionVisible('snooze1Day', $task);

        return;
    }

    $page->assertTableActionHidden('markDone', $task)->assertTableActionHidden('snooze1Day', $task);
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'admin' => [OrganizationRole::Admin, true],
    'member' => [OrganizationRole::Member, true],
    'viewer' => [OrganizationRole::Viewer, false],
]);

test('a viewer cannot complete a task through a forged call', function () {
    $viewer = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
    $this->actingAs($viewer);
    Filament::setTenant($this->org);
    $task = ($this->widgetTask)('Forged', '2026-10-19 09:00:00', $viewer);

    Livewire::test(MyTasksWidget::class)
        ->call('mountAction', 'markDone', [], ['table' => true, 'recordKey' => (string) $task->getKey()])
        ->assertActionNotMounted()
        ->call('callMountedAction');

    expect($task->refresh()->status)->toBe(TaskStatus::Pending);
});

test('the title links to the task only for those who can edit it', function () {
    $viewer = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
    $task = ($this->widgetTask)('Linked task', '2026-10-19 09:00:00', $this->me);
    $viewerTask = ($this->widgetTask)('Viewer task', '2026-10-19 09:00:00', $viewer);
    $url = TaskResource::getUrl('edit', ['record' => $task]);

    Livewire::test(MyTasksWidget::class)->assertSeeHtml('href="'.$url.'"');

    $this->actingAs($viewer);
    Filament::setTenant($this->org);

    Livewire::test(MyTasksWidget::class)
        ->assertCanSeeTableRecords([$viewerTask])
        ->assertDontSeeHtml('href="'.TaskResource::getUrl('edit', ['record' => $viewerTask]).'"');
});

test('it shows the contact, a dash without one, and escapes the title', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ana Silva']);
    ($this->widgetTask)('<script>alert(1)</script>', '2026-10-19 09:00:00', $this->me, ['contact_id' => $contact->id]);

    Livewire::test(MyTasksWidget::class)
        ->assertSee('Ana Silva')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

test('five tasks per page and the see all link', function () {
    foreach (range(1, 6) as $i) {
        ($this->widgetTask)("Overdue {$i}", "2026-10-1{$i} 09:00:00", $this->me);
    }

    Livewire::test(MyTasksWidget::class)
        ->assertSee('Overdue 5')
        ->assertDontSee('Overdue 6')
        ->call('nextPage')
        ->assertSee('Overdue 6');
});

test('the see all link is the mine tab in a team and the plain list when solo', function () {
    Livewire::test(MyTasksWidget::class)
        ->assertActionHasUrl(TestAction::make('seeAll')->table(), TaskResource::getUrl('index'));

    ($this->makeTeam)();

    Livewire::test(MyTasksWidget::class)
        ->assertActionHasUrl(TestAction::make('seeAll')->table(), TaskResource::getUrl('index', ['tab' => 'mine']));
});

test('the empty state', function () {
    ($this->makeTeam)();
    ($this->widgetTask)('Tomorrow', '2026-10-21 09:00:00', $this->me);

    Livewire::test(MyTasksWidget::class)
        ->assertSee('Nothing due today')
        ->assertSee('You have no overdue tasks and nothing due today.');
});

test('the dashboard renders the widget first', function () {
    $this->get(Filament::getUrl($this->org))
        ->assertOk()
        ->assertSeeLivewire(MyTasksWidget::class);
});

test('a forged array table search is turned into an empty search', function () {
    Livewire::test(MyTasksWidget::class)
        ->set('tableSearch', ['x'])
        ->assertSuccessful()
        ->assertSet('tableSearch', '');
});
