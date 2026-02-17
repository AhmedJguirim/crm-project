<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Facades\Filament;

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-001: Guest user accepts invitation
// ──────────────────────────────────────────────

test('guest clicking accept link is redirected to registration with invite session', function () {
    $invite = OrganizationInvite::factory()->create([
        'email' => 'newuser@example.com',
    ]);

    $response = $this->get(route('invite.accept', $invite->token));

    $response->assertRedirect(Filament::getRegistrationUrl());

    // Session should contain the invite token and email for the Register page
    expect(session('invite_token'))->toBe($invite->token)
        ->and(session('invite_email'))->toBe('newuser@example.com');
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-002: Existing user accepts matching invite
// ──────────────────────────────────────────────

test('logged-in user with matching email accepts invite immediately', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'member@example.com',
    ]);

    $invite = OrganizationInvite::factory()->create([
        'email' => 'member@example.com',
        'role' => OrganizationRole::Admin,
    ]);

    $this->actingAs($user)
        ->get(route('invite.accept', $invite->token))
        ->assertRedirect();

    // User should now be a member of the invited organization
    expect($invite->organization->hasMember($user))->toBeTrue()
        ->and($invite->organization->getMemberRole($user))->toBe(OrganizationRole::Admin);

    // Invite should be marked as accepted
    expect($invite->fresh()->status)->toBe(InviteStatus::Accepted);
});

test('accepted user appears in organization tenants', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'tenant@example.com',
    ]);

    $invite = OrganizationInvite::factory()->create([
        'email' => 'tenant@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('invite.accept', $invite->token));

    // User should now have 2 organizations: personal + invited
    $user->refresh();
    expect($user->organizations)->toHaveCount(2);
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-003: Logged-in user with non-matching email
// ──────────────────────────────────────────────

test('logged-in user with different email is redirected with error', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'wrong@example.com',
    ]);

    $invite = OrganizationInvite::factory()->create([
        'email' => 'correct@example.com',
    ]);

    $response = $this->actingAs($user)
        ->get(route('invite.accept', $invite->token));

    $response->assertRedirect(Filament::getLoginUrl());

    // User should NOT be a member of the invited organization
    expect($invite->organization->hasMember($user))->toBeFalse();

    // Invite should still be pending
    expect($invite->fresh()->status)->toBe(InviteStatus::Pending);
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-004: Invalid or expired token
// ──────────────────────────────────────────────

test('invalid token redirects to login with error', function () {
    $response = $this->get(route('invite.accept', 'totally-fake-token'));

    $response->assertRedirect(Filament::getLoginUrl());
});

test('expired invite redirects to login with error', function () {
    $invite = OrganizationInvite::factory()->create([
        'email' => 'expired@example.com',
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->get(route('invite.accept', $invite->token));

    $response->assertRedirect(Filament::getLoginUrl());

    // Status should be auto-updated to expired
    expect($invite->fresh()->status)->toBe(InviteStatus::Expired);
});

test('revoked invite redirects to login with error', function () {
    $invite = OrganizationInvite::factory()->revoked()->create([
        'email' => 'revoked@example.com',
    ]);

    $response = $this->get(route('invite.accept', $invite->token));

    $response->assertRedirect(Filament::getLoginUrl());
});

test('already accepted invite redirects to login with error', function () {
    $invite = OrganizationInvite::factory()->accepted()->create([
        'email' => 'accepted@example.com',
    ]);

    $response = $this->get(route('invite.accept', $invite->token));

    $response->assertRedirect(Filament::getLoginUrl());
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-005: Post-acceptance context
// ──────────────────────────────────────────────

test('accepted user can access the new organization dashboard', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'access@example.com',
    ]);

    $invite = OrganizationInvite::factory()->create([
        'email' => 'access@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('invite.accept', $invite->token));

    // User should be able to access the invited org's dashboard
    $this->get(Filament::getUrl($invite->organization))
        ->assertOk();
});

// ──────────────────────────────────────────────
// Edge cases
// ──────────────────────────────────────────────

test('same invite cannot be accepted twice', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'double@example.com',
    ]);

    $invite = OrganizationInvite::factory()->create([
        'email' => 'double@example.com',
    ]);

    // First acceptance
    $this->actingAs($user)
        ->get(route('invite.accept', $invite->token));

    expect($invite->fresh()->status)->toBe(InviteStatus::Accepted);

    // Second attempt should fail (already accepted)
    $response = $this->actingAs($user)
        ->get(route('invite.accept', $invite->token));

    $response->assertRedirect(Filament::getLoginUrl());
});

test('invite with viewer role assigns viewer role on acceptance', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'viewer@example.com',
    ]);

    $invite = OrganizationInvite::factory()->asViewer()->create([
        'email' => 'viewer@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('invite.accept', $invite->token));

    expect($invite->organization->getMemberRole($user))->toBe(OrganizationRole::Viewer);
});
