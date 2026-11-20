<?php

use App\Actions\Organization\TransferOwnership;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Filament\Notifications\Notification;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

/**
 * BUG-77: every write that can change who the Owner is takes the organization row lock and re-reads the roles under it.
 */
function lockTestSetup(): object
{
    $olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'olga-bug77@example.com']);
    $acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $olga->id]);
    $acme->members()->attach($olga->id, ['role' => OrganizationRole::Owner->value]);

    $people = ['olga' => $olga];

    foreach (['ann' => OrganizationRole::Admin, 'adam' => OrganizationRole::Admin, 'bob' => OrganizationRole::Member] as $name => $role) {
        $people[$name] = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => "{$name}-bug77@example.com"]);
        $acme->members()->attach($people[$name]->id, ['role' => $role->value]);
    }

    return (object) ['acme' => $acme, 'people' => $people];
}

function lockTestPivot(Organization $organization, User $user): OrganizationUser
{
    return OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->sole();
}

function lockTestRole(Organization $organization, User $user): ?string
{
    $role = DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)->value('role');

    return $role === null ? null : (string) $role;
}

function lockTestOwners(Organization $organization): int
{
    return DB::table('organization_user')->where('organization_id', $organization->id)->where('role', 'owner')->count();
}

function lockTestIsLock(QueryExecuted $query): bool
{
    return str_contains($query->sql, 'for update') && str_contains($query->sql, '"organizations"');
}

function lockTestAfterLock(Closure $write): void
{
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$fired, $write): void {
        if ($fired || ! lockTestIsLock($query)) {
            return;
        }

        $fired = true;
        $write();
    });
}

function lockTestAfterRoleRead(User $user, Closure $write): void
{
    $locked = false;
    $fired = false;

    DB::listen(function (QueryExecuted $query) use (&$locked, &$fired, $user, $write): void {
        if (lockTestIsLock($query)) {
            $locked = true;

            return;
        }

        if ($fired || ! $locked) {
            return;
        }

        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, '"organization_user"') && ($query->bindings[1] ?? null) === $user->id) {
            $fired = true;
            $write();
        }
    });
}

function lockTestMakeOwner(Organization $organization, User $newOwner, User $oldOwner): void
{
    DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $oldOwner->id)->update(['role' => 'admin']);
    DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $newOwner->id)->update(['role' => 'owner']);
}

/**
 * @return list<string>
 */
function lockTestLogEvents(TestHandler $log): array
{
    return array_column(securityLogEntries($log), 'event');
}

beforeEach(function () {
    $this->log = fakeSecurityLog();
    $setup = lockTestSetup();
    $this->acme = $setup->acme;
    $this->people = $setup->people;
    $this->notMember = 'The member you chose is no longer in this organization. Choose another member.';
});

function lockTestSignIn(object $test, string $who): void
{
    $test->actingAs($test->people[$who]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($test->acme);
}

test('a normal transfer still works', function () {
    lockTestSignIn($this, 'olga');

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('transferOwnership')->table(), ['new_owner_id' => $this->people['ann']->id])
        ->assertNotified('Ownership transferred');

    $lines = array_values(array_filter(securityLogEntries($this->log), fn (array $entry): bool => $entry['event'] === 'organization.ownership_transferred'));

    expect(lockTestRole($this->acme, $this->people['ann']))->toBe('owner')
        ->and(lockTestRole($this->acme, $this->people['olga']))->toBe('admin')
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('warning')
        ->and($lines[0]['context']['new_owner_user_id'])->toBe($this->people['ann']->id);
});

test('a new owner who left after the form was validated is caught by the re-read under the lock', function () {
    lockTestSignIn($this, 'olga');
    $bob = $this->people['bob'];

    lockTestAfterLock(fn () => DB::table('organization_user')->where('organization_id', $this->acme->id)->where('user_id', $bob->id)->delete());

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('transferOwnership')->table(), ['new_owner_id' => $bob->id])
        ->assertNotified(Notification::make()->danger()->title('Ownership not transferred')->body($this->notMember));

    expect(lockTestRole($this->acme, $this->people['olga']))->toBe('owner')
        ->and(lockTestRole($this->acme, $bob))->toBeNull()
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.ownership_transferred');
});

test('a new owner who disappears between the re-read and the promotion is caught by the zero-row guard', function () {
    lockTestSignIn($this, 'olga');
    $bob = $this->people['bob'];

    lockTestAfterRoleRead($bob, fn () => DB::table('organization_user')->where('organization_id', $this->acme->id)->where('user_id', $bob->id)->delete());

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('transferOwnership')->table(), ['new_owner_id' => $bob->id])
        ->assertNotified(Notification::make()->danger()->title('Ownership not transferred')->body($this->notMember));

    expect(lockTestRole($this->acme, $this->people['olga']))->toBe('owner')
        ->and(lockTestRole($this->acme, $bob))->toBeNull()
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.ownership_transferred');
});

test('a stale transfer from the owner who already gave ownership away is refused', function () {
    lockTestSignIn($this, 'olga');
    ['olga' => $olga, 'ann' => $ann, 'adam' => $adam] = $this->people;

    lockTestAfterLock(fn () => lockTestMakeOwner($this->acme, $ann, $olga));

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('transferOwnership')->table(), ['new_owner_id' => $adam->id])
        ->assertNotified(Notification::make()->danger()->title('Ownership not transferred')->body('You are no longer the owner of this organization.'));

    expect(lockTestRole($this->acme, $ann))->toBe('owner')
        ->and(lockTestRole($this->acme, $olga))->toBe('admin')
        ->and(lockTestRole($this->acme, $adam))->toBe('admin')
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.ownership_transferred');
});

test('a second transfer by the former owner is refused and changes nothing', function () {
    ['olga' => $olga, 'ann' => $ann, 'adam' => $adam] = $this->people;
    $action = app(TransferOwnership::class);

    expect($action->handle($olga, $this->acme, $ann->id))->toBeNull()
        ->and($action->handle($olga, $this->acme, $adam->id))->toBe('You are no longer the owner of this organization.')
        ->and(lockTestRole($this->acme, $ann))->toBe('owner')
        ->and(lockTestRole($this->acme, $adam))->toBe('admin')
        ->and(lockTestOwners($this->acme))->toBe(1);
});

test('a transfer refuses a non-owner caller', function () {
    ['olga' => $olga, 'ann' => $ann, 'bob' => $bob] = $this->people;

    expect(app(TransferOwnership::class)->handle($ann, $this->acme, $bob->id))->toBe('You are no longer the owner of this organization.')
        ->and(lockTestRole($this->acme, $olga))->toBe('owner')
        ->and(lockTestRole($this->acme, $bob))->toBe('member')
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.ownership_transferred');
});

test('a transfer to a user of another organization or an unknown id is refused', function (string $target) {
    ['olga' => $olga, 'bob' => $bob] = $this->people;
    $outsider = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'outsider-bug77@example.com']);
    $id = $target === 'outsider' ? $outsider->id : 999999999;

    expect(app(TransferOwnership::class)->handle($olga, $this->acme, $id))->toBe($this->notMember)
        ->and(lockTestRole($this->acme, $olga))->toBe('owner')
        ->and(lockTestRole($this->acme, $bob))->toBe('member')
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(DB::table('organization_user')->where('organization_id', $this->acme->id)->count())->toBe(4)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.ownership_transferred');
})->with(['outsider', 'unknown id']);

test('a transfer in an organization deleted meanwhile is refused', function () {
    ['olga' => $olga, 'bob' => $bob] = $this->people;
    $this->acme->delete();

    expect(app(TransferOwnership::class)->handle($olga, $this->acme, $bob->id))->toBe('This organization was deleted.')
        ->and(lockTestRole($this->acme, $olga))->toBe('owner')
        ->and(lockTestRole($this->acme, $bob))->toBe('member')
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.ownership_transferred');
});

test('a forged new owner id equal to the owner own id is refused', function () {
    $olga = $this->people['olga'];

    expect(app(TransferOwnership::class)->handle($olga, $this->acme, $olga->id))->toBe($this->notMember)
        ->and(lockTestRole($this->acme, $olga))->toBe('owner')
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.ownership_transferred');
});

test('a demotion that changes no row rolls the promotion back', function () {
    ['olga' => $olga, 'ann' => $ann] = $this->people;
    $lockedHandle = function () use ($olga, $ann): void {
        DB::listen(function (QueryExecuted $query) use ($olga): void {
            if (str_contains($query->sql, 'update "organization_user"') && in_array('owner', $query->bindings, true)) {
                DB::table('organization_user')->where('organization_id', $this->acme->id)->where('user_id', $olga->id)->delete();
            }
        });

        app(TransferOwnership::class)->handle($olga, $this->acme, $ann->id);
    };

    expect($lockedHandle)->toThrow(LogicException::class, 'Ownership transfer demoted 0 rows.')
        ->and(lockTestRole($this->acme, $ann))->toBe('admin')
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.ownership_transferred');
});

test('an admin removing a member who just became owner is refused', function () {
    lockTestSignIn($this, 'ann');
    ['olga' => $olga, 'bob' => $bob] = $this->people;

    lockTestAfterLock(fn () => lockTestMakeOwner($this->acme, $bob, $olga));

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('removeMember')->table(lockTestPivot($this->acme, $bob)))
        ->assertNotified(Notification::make()->danger()->title('Member not removed')->body("{$bob->name} is now the owner of this organization and can't be removed."));

    expect(lockTestRole($this->acme, $bob))->toBe('owner')
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.member_removed');
});

test('an admin changing the role of a member who just became owner is refused', function () {
    lockTestSignIn($this, 'ann');
    ['olga' => $olga, 'bob' => $bob] = $this->people;

    lockTestAfterLock(fn () => lockTestMakeOwner($this->acme, $bob, $olga));

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('changeRole')->table(lockTestPivot($this->acme, $bob)), ['role' => OrganizationRole::Viewer->value])
        ->assertNotified(Notification::make()->danger()->title('Role not changed')->body("{$bob->name} is now the owner of this organization. Their role can't be changed."));

    expect(lockTestRole($this->acme, $bob))->toBe('owner')
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.member_role_changed');
});

test('an admin acting on a member who left meanwhile is refused', function (string $action) {
    lockTestSignIn($this, 'ann');
    $bob = $this->people['bob'];
    $pivot = lockTestPivot($this->acme, $bob);

    lockTestAfterLock(fn () => DB::table('organization_user')->where('organization_id', $this->acme->id)->where('user_id', $bob->id)->delete());

    $page = Livewire::test(OrganizationMembers::class);

    if ($action === 'removeMember') {
        $page->callAction(TestAction::make('removeMember')->table($pivot))
            ->assertNotified(Notification::make()->danger()->title('Member not removed')->body("{$bob->name} is no longer a member of this organization."));
    } else {
        $page->callAction(TestAction::make('changeRole')->table($pivot), ['role' => OrganizationRole::Viewer->value])
            ->assertNotified(Notification::make()->danger()->title('Role not changed')->body("{$bob->name} is no longer a member of this organization."));
    }

    expect(lockTestRole($this->acme, $bob))->toBeNull()
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.member_removed')
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.member_role_changed');
})->with(['removeMember', 'changeRole']);

test('removing or changing a member of an organization deleted meanwhile is refused', function (string $action) {
    lockTestSignIn($this, 'ann');
    $bob = $this->people['bob'];
    $pivot = lockTestPivot($this->acme, $bob);

    $page = Livewire::test(OrganizationMembers::class);

    $this->acme->delete();

    if ($action === 'removeMember') {
        $page->callAction(TestAction::make('removeMember')->table($pivot))
            ->assertNotified(Notification::make()->danger()->title('Member not removed')->body('This organization was deleted.'));
    } else {
        $page->callAction(TestAction::make('changeRole')->table($pivot), ['role' => OrganizationRole::Viewer->value])
            ->assertNotified(Notification::make()->danger()->title('Role not changed')->body('This organization was deleted.'));
    }

    expect(lockTestRole($this->acme, $bob))->toBe('member')
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.member_removed')
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.member_role_changed');
})->with(['removeMember', 'changeRole']);

test('a member leaving after just receiving ownership is refused', function () {
    lockTestSignIn($this, 'bob');
    ['olga' => $olga, 'bob' => $bob] = $this->people;

    lockTestAfterLock(fn () => lockTestMakeOwner($this->acme, $bob, $olga));

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'Acme')
        ->call('callMountedAction')
        ->assertHasNoErrors()
        ->assertNoRedirect()
        ->assertNotified(Notification::make()->danger()->title('You have not left')->body('You are now the owner of this organization. Transfer ownership before leaving.'));

    expect(lockTestRole($this->acme, $bob))->toBe('owner')
        ->and(lockTestOwners($this->acme))->toBe(1)
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.member_left');
});

test('a member who was removed before leaving is told so and nothing is logged', function () {
    lockTestSignIn($this, 'bob');
    $bob = $this->people['bob'];

    lockTestAfterLock(fn () => DB::table('organization_user')->where('organization_id', $this->acme->id)->where('user_id', $bob->id)->delete());

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'Acme')
        ->call('callMountedAction')
        ->assertNoRedirect()
        ->assertNotified(Notification::make()->danger()->title('You have not left')->body('You are no longer a member of this organization.'));

    expect(lockTestLogEvents($this->log))->not->toContain('organization.member_left');
});

test('an ordinary leave still logs the role read under the lock', function () {
    lockTestSignIn($this, 'bob');
    $bob = $this->people['bob'];

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'Acme')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $left = array_values(array_filter(securityLogEntries($this->log), fn (array $entry): bool => $entry['event'] === 'organization.member_left'));

    expect(lockTestRole($this->acme, $bob))->toBeNull()
        ->and($left)->toHaveCount(1)
        ->and($left[0]['context']['role'])->toBe('member');
});

test('leaving an organization deleted meanwhile is refused', function () {
    lockTestSignIn($this, 'bob');
    $bob = $this->people['bob'];

    $topbar = Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'Acme');

    $this->acme->delete();

    $topbar->call('callMountedAction')
        ->assertNoRedirect()
        ->assertNotified(Notification::make()->danger()->title('You have not left')->body('This organization was deleted.'));

    expect(lockTestRole($this->acme, $bob))->toBe('member')
        ->and(lockTestLogEvents($this->log))->not->toContain('organization.member_left');
});

function lockTestSetRole(Organization $organization, User $user, string $role): void
{
    DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->id)->update(['role' => $role]);
}

/**
 * @return list<array{event: string, level: string, context: array<string, mixed>}>
 */
function lockTestLogLines(TestHandler $log, string $event): array
{
    return array_values(array_filter(securityLogEntries($log), fn (array $entry): bool => $entry['event'] === $event));
}

test('a removal logs the role read under the lock, not the one the page showed', function () {
    lockTestSignIn($this, 'ann');
    $bob = $this->people['bob'];
    $pivot = lockTestPivot($this->acme, $bob);

    lockTestAfterLock(fn () => lockTestSetRole($this->acme, $bob, 'viewer'));

    Livewire::test(OrganizationMembers::class)->callAction(TestAction::make('removeMember')->table($pivot));

    $lines = lockTestLogLines($this->log, 'organization.member_removed');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context']['role'])->toBe('viewer')
        ->and(lockTestRole($this->acme, $bob))->toBeNull();
});

test('a role change logs the role read under the lock as from_role', function () {
    lockTestSignIn($this, 'ann');
    $bob = $this->people['bob'];
    $pivot = lockTestPivot($this->acme, $bob);

    lockTestAfterLock(fn () => lockTestSetRole($this->acme, $bob, 'viewer'));

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('changeRole')->table($pivot), ['role' => OrganizationRole::Admin->value]);

    $lines = lockTestLogLines($this->log, 'organization.member_role_changed');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context']['from_role'])->toBe('viewer')
        ->and($lines[0]['context']['to_role'])->toBe('admin')
        ->and(lockTestRole($this->acme, $bob))->toBe('admin');
});

test('a role change to the role the member already has under the lock writes no log line', function () {
    lockTestSignIn($this, 'ann');
    $bob = $this->people['bob'];
    $pivot = lockTestPivot($this->acme, $bob);

    lockTestAfterLock(fn () => lockTestSetRole($this->acme, $bob, 'admin'));

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('changeRole')->table($pivot), ['role' => OrganizationRole::Admin->value]);

    expect(lockTestLogLines($this->log, 'organization.member_role_changed'))->toBe([])
        ->and(lockTestRole($this->acme, $bob))->toBe('admin');
});

test('leaving logs the role read under the lock', function () {
    lockTestSignIn($this, 'bob');
    $bob = $this->people['bob'];

    lockTestAfterLock(fn () => lockTestSetRole($this->acme, $bob, 'viewer'));

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'Acme')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $lines = lockTestLogLines($this->log, 'organization.member_left');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context']['role'])->toBe('viewer')
        ->and(lockTestRole($this->acme, $bob))->toBeNull();
});
