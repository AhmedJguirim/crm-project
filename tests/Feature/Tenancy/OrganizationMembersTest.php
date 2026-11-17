<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

// TSK-2026-0016 AC-001: View members list as admin
test('organization has correct members after creation', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $member = User::factory()->create();
    $org->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    expect($org->members)->toHaveCount(2);
});

// TSK-2026-0016 AC-002: Change member role
test('member role can be changed', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $member = User::factory()->create();
    $org->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    expect($org->getMemberRole($member))->toBe(OrganizationRole::Member);

    $org->members()->updateExistingPivot($member->id, [
        'role' => OrganizationRole::Admin->value,
    ]);

    $org->refresh();
    expect($org->getMemberRole($member))->toBe(OrganizationRole::Admin);
});

// TSK-2026-0016 AC-003: Remove member
test('member can be removed from organization', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $member = User::factory()->create();
    $org->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    expect($org->members)->toHaveCount(2);

    $org->members()->detach($member->id);
    $org->refresh();

    expect($org->members)->toHaveCount(1)
        ->and($org->hasMember($member))->toBeFalse();
});

test('removed member loses tenant access', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $member = User::factory()->create();
    $org->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    expect($member->canAccessTenant($org))->toBeTrue();

    $org->members()->detach($member->id);

    expect($member->canAccessTenant($org))->toBeFalse();
});

// TSK-2026-0016 AC-004: Transfer ownership
test('ownership can be transferred atomically', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = Organization::factory()->create(['created_by' => $owner->id]);
    $owner->organizations()->attach($org, ['role' => OrganizationRole::Owner->value]);

    $newOwner = User::factory()->create();
    $org->members()->attach($newOwner, ['role' => OrganizationRole::Admin->value]);

    DB::transaction(function () use ($org, $owner, $newOwner) {
        $org->members()->updateExistingPivot($owner->id, [
            'role' => OrganizationRole::Admin->value,
        ]);
        $org->members()->updateExistingPivot($newOwner->id, [
            'role' => OrganizationRole::Owner->value,
        ]);
    });

    $org->refresh();

    expect($org->getMemberRole($owner))->toBe(OrganizationRole::Admin)
        ->and($org->getMemberRole($newOwner))->toBe(OrganizationRole::Owner)
        ->and($org->isOwner($newOwner))->toBeTrue()
        ->and($org->isOwner($owner))->toBeFalse();
});

// TSK-2026-0016 AC-005: Permission restrictions (Policy tests)
test('owner can manage members', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $policy = new OrganizationPolicy;

    expect($policy->manageMembers($owner, $org))->toBeTrue();
});

test('admin can manage members', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $admin = User::factory()->create();
    $org->members()->attach($admin, ['role' => OrganizationRole::Admin->value]);

    $policy = new OrganizationPolicy;

    expect($policy->manageMembers($admin, $org))->toBeTrue();
});

test('regular member cannot manage members', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $member = User::factory()->create();
    $org->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $policy = new OrganizationPolicy;

    expect($policy->manageMembers($member, $org))->toBeFalse();
});

test('viewer cannot manage members', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $viewer = User::factory()->create();
    $org->members()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);

    $policy = new OrganizationPolicy;

    expect($policy->manageMembers($viewer, $org))->toBeFalse();
});

test('owner cannot be removed via policy', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $admin = User::factory()->create();
    $org->members()->attach($admin, ['role' => OrganizationRole::Admin->value]);

    $policy = new OrganizationPolicy;

    expect($policy->removeMember($admin, $org, $owner))->toBeFalse();
});

test('user cannot remove themselves via policy', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $policy = new OrganizationPolicy;

    expect($policy->removeMember($owner, $org, $owner))->toBeFalse();
});

test('owner can remove non-owner member via policy', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $member = User::factory()->create();
    $org->members()->attach($member, ['role' => OrganizationRole::Member->value]);

    $policy = new OrganizationPolicy;

    expect($policy->removeMember($owner, $org, $member))->toBeTrue();
});

test('only owner can transfer ownership via policy', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $admin = User::factory()->create();
    $org->members()->attach($admin, ['role' => OrganizationRole::Admin->value]);

    $policy = new OrganizationPolicy;

    expect($policy->transferOwnership($owner, $org))->toBeTrue()
        ->and($policy->transferOwnership($admin, $org))->toBeFalse();
});

test('owner role cannot be changed via policy', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $admin = User::factory()->create();
    $org->members()->attach($admin, ['role' => OrganizationRole::Admin->value]);

    $policy = new OrganizationPolicy;

    expect($policy->changeRole($admin, $org, $owner))->toBeFalse();
});

// Edge case: single-member organization
test('single member org has only owner', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    expect($org->members)->toHaveCount(1)
        ->and($org->isOwner($owner))->toBeTrue();
});

// Edge case: personal org cannot be deleted
test('personal organization cannot be deleted via policy', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $owner->personalOrganization();

    $policy = new OrganizationPolicy;

    expect($policy->delete($owner, $org))->toBeFalse();
});

test('non-personal organization can be deleted by owner', function () {
    $owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = Organization::factory()->create(['created_by' => $owner->id]);
    $owner->organizations()->attach($org, ['role' => OrganizationRole::Owner->value]);

    $policy = new OrganizationPolicy;

    expect($policy->delete($owner, $org))->toBeTrue();
});

/*
 * SEC-55: the four member actions are authorized by OrganizationPolicy through ->authorize().
 */

function membersPageSetup(): object
{
    $olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'olga-sec55@example.com']);
    $acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $olga->id]);
    $acme->members()->attach($olga->id, ['role' => OrganizationRole::Owner->value]);

    $people = ['olga' => $olga];

    foreach (['ann' => OrganizationRole::Admin, 'adam' => OrganizationRole::Admin, 'bob' => OrganizationRole::Member] as $name => $role) {
        $people[$name] = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => "{$name}-sec55@example.com"]);
        $acme->members()->attach($people[$name]->id, ['role' => $role->value]);
    }

    return (object) ['acme' => $acme, 'people' => $people];
}

function membersPagePivot(Organization $organization, User $user): OrganizationUser
{
    return OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->sole();
}

test('the policy refuses a member changing their own role', function () {
    $setup = membersPageSetup();
    ['olga' => $olga, 'ann' => $ann, 'bob' => $bob] = $setup->people;
    $policy = new OrganizationPolicy;

    expect($policy->changeRole($ann, $setup->acme, $ann))->toBeFalse()
        ->and($policy->changeRole($ann, $setup->acme, $bob))->toBeTrue()
        ->and($policy->changeRole($olga, $setup->acme, $olga))->toBeFalse()
        ->and($policy->changeRole($olga, $setup->acme, $bob))->toBeTrue()
        ->and($policy->changeRole($bob, $setup->acme, $ann))->toBeFalse();
});

test('row action visibility per actor and target', function (string $actor, string $action, string $target, bool $visible) {
    $setup = membersPageSetup();

    $this->actingAs($setup->people[$actor]);
    Filament::setTenant($setup->acme);

    $test = Livewire::test(OrganizationMembers::class);
    $testAction = TestAction::make($action)->table(membersPagePivot($setup->acme, $setup->people[$target]));

    $visible ? $test->assertActionVisible($testAction) : $test->assertActionHidden($testAction);
})->with([
    'owner changes bob' => ['olga', 'changeRole', 'bob', true],
    'owner changes self' => ['olga', 'changeRole', 'olga', false],
    'admin changes admin' => ['ann', 'changeRole', 'adam', true],
    'admin changes self' => ['ann', 'changeRole', 'ann', false],
    'admin changes owner' => ['ann', 'changeRole', 'olga', false],
    'owner removes admin' => ['olga', 'removeMember', 'ann', true],
    'admin removes member' => ['ann', 'removeMember', 'bob', true],
    'admin removes self' => ['ann', 'removeMember', 'ann', false],
    'admin removes owner' => ['ann', 'removeMember', 'olga', false],
]);

test('header actions per actor', function (string $actor, bool $transferVisible) {
    $setup = membersPageSetup();

    $this->actingAs($setup->people[$actor]);
    Filament::setTenant($setup->acme);

    $test = Livewire::test(OrganizationMembers::class)->assertActionVisible(TestAction::make('inviteMember')->table());

    $transferVisible
        ? $test->assertActionVisible(TestAction::make('transferOwnership')->table())
        : $test->assertActionHidden(TestAction::make('transferOwnership')->table());
})->with([
    'owner' => ['olga', true],
    'admin' => ['ann', false],
]);

test('transfer ownership is hidden for an owner alone in the organization', function () {
    $olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $solo = Organization::factory()->create(['name' => 'Solo', 'created_by' => $olga->id]);
    $solo->members()->attach($olga->id, ['role' => OrganizationRole::Owner->value]);

    $this->actingAs($olga);
    Filament::setTenant($solo);

    Livewire::test(OrganizationMembers::class)
        ->assertActionHidden(TestAction::make('transferOwnership')->table());
});

test('an admin forging a role change on their own row is refused', function () {
    $setup = membersPageSetup();
    ['ann' => $ann] = $setup->people;

    $this->actingAs($ann);
    Filament::setTenant($setup->acme);

    Livewire::test(OrganizationMembers::class)
        ->call('mountAction', 'changeRole', [], ['table' => true, 'recordKey' => (string) membersPagePivot($setup->acme, $ann)->getKey()])
        ->assertSet('mountedActions', [])
        ->call('callMountedAction', ['role' => OrganizationRole::Owner->value])
        ->assertSet('mountedActions', []);

    $setup->acme->refresh();

    expect($setup->acme->getMemberRole($ann))->toBe(OrganizationRole::Admin);
});

test('an admin forging a removal of the owner is refused', function () {
    $setup = membersPageSetup();
    ['ann' => $ann, 'olga' => $olga] = $setup->people;

    $this->actingAs($ann);
    Filament::setTenant($setup->acme);

    Livewire::test(OrganizationMembers::class)
        ->call('mountAction', 'removeMember', [], ['table' => true, 'recordKey' => (string) membersPagePivot($setup->acme, $olga)->getKey()])
        ->assertSet('mountedActions', [])
        ->call('callMountedAction')
        ->assertSet('mountedActions', []);

    expect($setup->acme->members()->whereKey($olga->id)->exists())->toBeTrue();
});

test('an admin forging the ownership transfer is refused', function () {
    $setup = membersPageSetup();
    ['ann' => $ann, 'olga' => $olga] = $setup->people;

    $this->actingAs($ann);
    Filament::setTenant($setup->acme);

    Livewire::test(OrganizationMembers::class)
        ->call('mountAction', 'transferOwnership', [], ['table' => true])
        ->assertSet('mountedActions', [])
        ->call('callMountedAction', ['new_owner_id' => $ann->id])
        ->assertSet('mountedActions', []);

    $setup->acme->refresh();

    expect($setup->acme->getMemberRole($olga))->toBe(OrganizationRole::Owner)
        ->and($setup->acme->getMemberRole($ann))->toBe(OrganizationRole::Admin);
});
