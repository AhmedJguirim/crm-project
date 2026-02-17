<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvite;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
});

// ──────────────────────────────────────────────
// TSK-2026-0014 AC-001: Successful invite to new email
// ──────────────────────────────────────────────

test('invite record is created with correct attributes', function () {
    $invite = OrganizationInvite::create([
        'organization_id' => $this->org->id,
        'email' => 'newuser@example.com',
        'token' => OrganizationInvite::generateToken(),
        'role' => OrganizationRole::Member->value,
        'status' => InviteStatus::Pending->value,
        'invited_by' => $this->owner->id,
        'expires_at' => now()->addDays(7),
    ]);

    expect($invite)->not->toBeNull()
        ->and($invite->email)->toBe('newuser@example.com')
        ->and($invite->status)->toBe(InviteStatus::Pending)
        ->and($invite->role)->toBe(OrganizationRole::Member)
        ->and($invite->organization_id)->toBe($this->org->id)
        ->and($invite->invited_by)->toBe($this->owner->id)
        ->and($invite->expires_at->isFuture())->toBeTrue()
        ->and(strlen($invite->token))->toBe(64);
});

test('invitation email can be sent', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'test@example.com',
        'invited_by' => $this->owner->id,
    ]);

    Mail::to($invite->email)->send(new OrganizationInvitationMail($invite));

    // The mailable implements ShouldQueue, so it gets queued rather than sent
    Mail::assertQueued(OrganizationInvitationMail::class, function ($mail) {
        return $mail->hasTo('test@example.com');
    });
});

test('invite token is unique and 64 characters', function () {
    $token1 = OrganizationInvite::generateToken();
    $token2 = OrganizationInvite::generateToken();

    expect(strlen($token1))->toBe(64)
        ->and(strlen($token2))->toBe(64)
        ->and($token1)->not->toBe($token2);
});

// ──────────────────────────────────────────────
// TSK-2026-0014 AC-002: Invite to existing user email
// ──────────────────────────────────────────────

test('invite can be created for an existing registered user', function () {
    $existingUser = User::factory()->create(['email' => 'existing@example.com']);

    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'existing@example.com',
        'invited_by' => $this->owner->id,
    ]);

    expect($invite->email)->toBe($existingUser->email)
        ->and($invite->isValid())->toBeTrue();
});

// ──────────────────────────────────────────────
// TSK-2026-0014 AC-003: Permission enforcement
// ──────────────────────────────────────────────

test('organization hasPendingInviteFor detects existing pending invite', function () {
    OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'pending@example.com',
        'invited_by' => $this->owner->id,
        'status' => InviteStatus::Pending,
        'expires_at' => now()->addDays(7),
    ]);

    expect($this->org->hasPendingInviteFor('pending@example.com'))->toBeTrue()
        ->and($this->org->hasPendingInviteFor('other@example.com'))->toBeFalse();
});

test('revoked invite does not count as pending', function () {
    OrganizationInvite::factory()->revoked()->create([
        'organization_id' => $this->org->id,
        'email' => 'revoked@example.com',
        'invited_by' => $this->owner->id,
    ]);

    expect($this->org->hasPendingInviteFor('revoked@example.com'))->toBeFalse();
});

// ──────────────────────────────────────────────
// TSK-2026-0014 AC-004: Pending invites management
// ──────────────────────────────────────────────

test('invite can be resent with new token and extended expiry', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'resend@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $oldToken = $invite->token;
    $oldExpiry = $invite->expires_at;

    // Simulate time passing
    $this->travel(2)->days();

    $invite->regenerateToken(7);

    expect($invite->token)->not->toBe($oldToken)
        ->and($invite->expires_at->isAfter($oldExpiry))->toBeTrue()
        ->and($invite->status)->toBe(InviteStatus::Pending);
});

test('invite can be revoked', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'revoke@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $invite->revoke();

    expect($invite->fresh()->status)->toBe(InviteStatus::Revoked)
        ->and($invite->fresh()->isValid())->toBeFalse();
});

// ──────────────────────────────────────────────
// TSK-2026-0014 AC-005: Validation and error handling
// ──────────────────────────────────────────────

test('isValid returns false for expired invite', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'expired@example.com',
        'invited_by' => $this->owner->id,
        'expires_at' => now()->subDay(),
    ]);

    expect($invite->isValid())->toBeFalse()
        ->and($invite->isExpired())->toBeTrue();
});

test('isValid returns false for revoked invite', function () {
    $invite = OrganizationInvite::factory()->revoked()->create([
        'organization_id' => $this->org->id,
        'email' => 'revoked@example.com',
        'invited_by' => $this->owner->id,
    ]);

    expect($invite->isValid())->toBeFalse();
});

test('isValid returns true for fresh pending invite', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'valid@example.com',
        'invited_by' => $this->owner->id,
    ]);

    expect($invite->isValid())->toBeTrue();
});

// ──────────────────────────────────────────────
// Edge cases
// ──────────────────────────────────────────────

test('accept URL contains the token', function () {
    $url = OrganizationInvite::acceptUrl('test-token-123');

    expect($url)->toContain('/invite/accept/test-token-123');
});

test('invite belongs to correct organization and inviter', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'invited_by' => $this->owner->id,
    ]);

    expect($invite->organization->id)->toBe($this->org->id)
        ->and($invite->inviter->id)->toBe($this->owner->id);
});

test('markAccepted changes status to accepted', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'invited_by' => $this->owner->id,
    ]);

    $invite->markAccepted();

    expect($invite->fresh()->status)->toBe(InviteStatus::Accepted);
});
