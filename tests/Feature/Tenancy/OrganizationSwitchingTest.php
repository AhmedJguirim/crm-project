<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

// TSK-2026-0012 AC-001: Switch Organization with multiple available
test('user can access dashboard of their organization', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $user->personalOrganization();

    $this->actingAs($user)
        ->get(Filament::getUrl($org))
        ->assertOk();
});

test('user cannot access another users organization', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $this->actingAs($user)
        ->get(Filament::getUrl($otherOrg))
        ->assertNotFound();
});

test('user with multiple organizations can access each', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $personalOrg = $user->personalOrganization();

    $secondOrg = Organization::factory()->create(['created_by' => $user->id]);
    $user->organizations()->attach($secondOrg, ['role' => OrganizationRole::Owner->value]);

    $this->actingAs($user)
        ->get(Filament::getUrl($personalOrg))
        ->assertOk();

    $this->get(Filament::getUrl($secondOrg))
        ->assertOk();
});

// TSK-2026-0012 AC-002: Default Organization on fresh login
test('getTenants returns all user organizations', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $secondOrg = Organization::factory()->create(['created_by' => $user->id]);
    $user->organizations()->attach($secondOrg, ['role' => OrganizationRole::Member->value]);

    $tenants = $user->getTenants(Filament::getCurrentOrDefaultPanel());

    expect($tenants)->toHaveCount(2);
});

// TSK-2026-0012 AC-003: Fallback to personal Organization
test('personal organization is accessible as default', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $personalOrg = $user->personalOrganization();

    expect($personalOrg)->not->toBeNull()
        ->and($personalOrg->personal_team)->toBeTrue();
});

// TSK-2026-0012 AC-005: Single Organization case
test('user with single organization has one tenant', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $tenants = $user->getTenants(Filament::getCurrentOrDefaultPanel());

    expect($tenants)->toHaveCount(1);
});

// Security: canAccessTenant enforcement
test('canAccessTenant returns true for member organization', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $org = $user->personalOrganization();

    expect($user->canAccessTenant($org))->toBeTrue();
});

test('canAccessTenant returns false for non-member organization', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    expect($user->canAccessTenant($otherOrg))->toBeFalse();
});
