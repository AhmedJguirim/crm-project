<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Policies\OrganizationPolicy;
use Illuminate\Support\Facades\DB;

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
