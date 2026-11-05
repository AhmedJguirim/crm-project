<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-001: Guest user accepts invitation
// ──────────────────────────────────────────────

test('guest clicking accept link is redirected to registration with the invite id in session', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'newuser@example.com',
    ]);

    $response = $this->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getRegistrationUrl());

    expect(session('invite_id'))->toBe($invite->id)
        ->and(session('invite_token'))->toBeNull()
        ->and(session('invite_email'))->toBe('newuser@example.com');
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-002: Existing user accepts matching invite
// ──────────────────────────────────────────────

test('logged-in user with matching email accepts invite immediately', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'member@example.com',
    ]);

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'member@example.com',
        'role' => OrganizationRole::Admin,
    ]);

    $this->actingAs($user)
        ->get(route('invite.accept', $token))
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

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'tenant@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('invite.accept', $token));

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

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'correct@example.com',
    ]);

    $response = $this->actingAs($user)
        ->get(route('invite.accept', $token));

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
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'expired@example.com',
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getLoginUrl());

    // Status should be auto-updated to expired
    expect($invite->fresh()->status)->toBe(InviteStatus::Expired);
});

test('revoked invite redirects to login with error', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->revoked()->create([
        'email' => 'revoked@example.com',
    ]);

    $response = $this->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getLoginUrl());
});

test('already accepted invite redirects to login with error', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->accepted()->create([
        'email' => 'accepted@example.com',
    ]);

    $response = $this->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getLoginUrl());
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-005: Post-acceptance context
// ──────────────────────────────────────────────

test('accepted user can access the new organization dashboard', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'access@example.com',
    ]);

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'access@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('invite.accept', $token));

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

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'double@example.com',
    ]);

    // First acceptance
    $this->actingAs($user)
        ->get(route('invite.accept', $token));

    expect($invite->fresh()->status)->toBe(InviteStatus::Accepted);

    // Second attempt should fail (already accepted)
    $response = $this->actingAs($user)
        ->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getLoginUrl());
});

test('invite with viewer role assigns viewer role on acceptance', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'viewer@example.com',
    ]);

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->asViewer()->create([
        'email' => 'viewer@example.com',
    ]);

    $this->actingAs($user)
        ->get(route('invite.accept', $token));

    expect($invite->organization->getMemberRole($user))->toBe(OrganizationRole::Viewer);
});

// ──────────────────────────────────────────────
// SEC-13: the guest flow keeps the invite id, not the token
// ──────────────────────────────────────────────

test('a guest who opens the link and registers joins the organization', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->asViewer()->create([
        'email' => 'new@example.com',
    ]);

    $this->get(route('invite.accept', $token))->assertRedirect(Filament::getRegistrationUrl());

    expect(session('invite_id'))->toBe($invite->id)
        ->and(session('invite_token'))->toBeNull();

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'new@example.com')->sole();

    expect($invite->organization->getMemberRole($user))->toBe(OrganizationRole::Viewer)
        ->and($invite->fresh()->status)->toBe(InviteStatus::Accepted)
        ->and(session('invite_id'))->toBeNull();
});

test('an existing user who opens the link and logs in joins the organization', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'bob@example.com',
        'password' => 'password',
    ]);
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'bob@example.com',
    ]);

    $this->get(route('invite.accept', $token))->assertRedirect(Filament::getLoginUrl());

    Livewire::test(Login::class)
        ->fillForm(['email' => 'bob@example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($invite->organization->hasMember($user))->toBeTrue()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Accepted);
});

test('the invite in the session is only accepted for its own email', function () {
    $eve = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'eve@example.com',
        'password' => 'password',
    ]);
    $invite = OrganizationInvite::factory()->create(['email' => 'bob@example.com']);

    $this->withSession(['invite_id' => $invite->id]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'eve@example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($invite->organization->hasMember($eve))->toBeFalse()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Pending)
        ->and(session('invite_id'))->toBeNull();
});

test('an expired invite in the session is not accepted at registration', function () {
    $invite = OrganizationInvite::factory()->expired()->create(['email' => 'late@example.com']);

    $this->withSession(['invite_id' => $invite->id]);

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Late Person',
            'email' => 'late@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'late@example.com')->sole();

    expect($invite->organization->hasMember($user))->toBeFalse();
});

test('a revoked invite in the session is not accepted at login', function (string $state) {
    $bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'bob@example.com',
        'password' => 'password',
    ]);
    $invite = OrganizationInvite::factory()->{$state}()->create(['email' => 'bob@example.com']);
    $status = $invite->status;

    $this->withSession(['invite_id' => $invite->id]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'bob@example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($invite->organization->hasMember($bob))->toBeFalse()
        ->and($invite->fresh()->status)->toBe($status)
        ->and(session('invite_id'))->toBeNull();
})->with(['revoked' => ['revoked'], 'expired' => ['expired']]);

test('the invite in the session is only accepted for its own email at registration', function () {
    $invite = OrganizationInvite::factory()->create(['email' => 'bob@example.com']);

    $this->withSession(['invite_id' => $invite->id]);

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $eve = User::query()->where('email', 'eve@example.com')->sole();

    expect($invite->organization->hasMember($eve))->toBeFalse()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Pending)
        ->and(session('invite_id'))->toBeNull();
});
