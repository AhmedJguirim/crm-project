<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Livewire\Tenancy\InvitationsList;
use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;

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
        'token_hash' => OrganizationInvite::hashToken(OrganizationInvite::generateToken()),
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
        ->and(strlen($invite->token_hash))->toBe(64)
        ->and($invite->token_hash)->toMatch('/^[0-9a-f]{64}\z/');
});

test('invitation email can be sent', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'test@example.com',
        'invited_by' => $this->owner->id,
    ]);

    Mail::to($invite->email)->send(new OrganizationInvitationMail($invite, OrganizationInvite::generateToken()));

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

    $oldHash = $invite->token_hash;
    $oldExpiry = $invite->expires_at;

    // Simulate time passing
    $this->travel(2)->days();

    $newToken = $invite->regenerateToken(7);

    expect($newToken)->toHaveLength(64)
        ->and($invite->token_hash)->not->toBe($oldHash)
        ->and($invite->token_hash)->toBe(OrganizationInvite::hashToken($newToken))
        ->and($invite->fresh()->token_hash)->toBe(OrganizationInvite::hashToken($newToken))
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

// ──────────────────────────────────────────────
// SEC-13: only the hash of the token is stored
// ──────────────────────────────────────────────

test('inviting stores a hash and mails the token', function () {
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('inviteMember')->table(), [
            'email' => 'bob@example.com',
            'role' => OrganizationRole::Member->value,
        ])
        ->assertHasNoActionErrors();

    $invite = OrganizationInvite::query()->where('email', 'bob@example.com')->sole();

    Mail::assertQueued(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use ($invite) {
        return $mail->hasTo('bob@example.com')
            && strlen($mail->token) === 64
            && $mail->token !== $invite->token_hash
            && $invite->token_hash === hash('sha256', $mail->token)
            && str_contains($mail->render(), 'href="'.OrganizationInvite::acceptUrl($mail->token).'"');
    });
});

test('the stored hash is not a working link', function () {
    $invite = OrganizationInvite::factory()->withToken('tok-'.str_repeat('a', 60))->create([
        'organization_id' => $this->org->id,
        'email' => 'bob@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->get(route('invite.accept', $invite->token_hash))->assertRedirect(Filament::getLoginUrl());

    $bodies = collect(session('filament.notifications'))->pluck('body')->all();

    expect($bodies)->toContain('This invitation link is invalid.')
        ->and(session('invite_id'))->toBeNull();
});

test('resending replaces the token', function () {
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $invite = OrganizationInvite::factory()->withToken($old = Str::random(64))->create([
        'organization_id' => $this->org->id,
        'email' => 'resend@example.com',
        'invited_by' => $this->owner->id,
    ]);

    Livewire::test(InvitationsList::class)
        ->callAction(TestAction::make('resend')->table($invite))
        ->assertHasNoActionErrors();

    $invite->refresh();
    $sentToken = null;

    Mail::assertQueued(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use ($invite, $old, &$sentToken) {
        $sentToken = $mail->token;

        return $mail->hasTo('resend@example.com')
            && $mail->token !== $old
            && $invite->token_hash === hash('sha256', $mail->token);
    });

    $this->get(route('invite.accept', $old))->assertRedirect(Filament::getLoginUrl());
    expect(collect(session('filament.notifications'))->pluck('body')->all())->toContain('This invitation link is invalid.');

    auth()->logout();
    session()->flush();

    $this->get(route('invite.accept', $sentToken))->assertRedirect(Filament::getRegistrationUrl());
    expect(session('invite_id'))->toBe($invite->id);
});

test('the invitation mail is encrypted in the queue', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->org->id,
        'invited_by' => $this->owner->id,
    ]);

    expect(new OrganizationInvitationMail($invite, 'x'))->toBeInstanceOf(ShouldBeEncrypted::class);
});

test('the invites table holds token_hash and no token column', function () {
    expect(Schema::hasColumn('organization_invites', 'token_hash'))->toBeTrue()
        ->and(Schema::hasColumn('organization_invites', 'token'))->toBeFalse();
});

test('the token hash is not serialised and findByToken only matches the plain token', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'organization_id' => $this->org->id,
        'invited_by' => $this->owner->id,
    ]);

    expect($invite->toArray())->not->toHaveKey('token_hash')
        ->and(OrganizationInvite::findByToken($token)?->is($invite))->toBeTrue()
        ->and(OrganizationInvite::findByToken($invite->token_hash))->toBeNull();
});
