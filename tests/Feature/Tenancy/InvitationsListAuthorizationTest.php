<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Livewire\Tenancy\InvitationsList;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function invitationsAuthUser(Organization $organization, string $email, OrganizationRole $role): User
{
    $user = User::factory()->onboardingCompleted()->create(['email' => $email]);
    $organization->members()->attach($user->id, ['role' => $role->value]);

    return $user;
}

beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->onboardingCompleted()->create(['email' => 'owner@acme.test']);
    $this->acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $this->owner->id]);
    $this->acme->members()->attach($this->owner->id, ['role' => OrganizationRole::Owner->value]);
    $this->admin = invitationsAuthUser($this->acme, 'admin@acme.test', OrganizationRole::Admin);

    $this->invite = OrganizationInvite::factory()->withToken('p'.str_repeat('a', 63))->create([
        'organization_id' => $this->acme->id,
        'email' => 'pending@example.com',
        'invited_by' => $this->owner->id,
    ]);

    $this->actingAs($this->owner);
    Filament::setTenant($this->acme);
});

function invitationsAuthAssertUntouched(object $test): void
{
    $fresh = $test->invite->fresh();

    expect($fresh->status)->toBe(InviteStatus::Pending)
        ->and($fresh->token_hash)->toBe($test->invite->token_hash)
        ->and($fresh->expires_at->equalTo($test->invite->expires_at))->toBeTrue()
        ->and(RateLimiter::attempts("invitations:organization:{$test->acme->id}"))->toBe(0)
        ->and(RateLimiter::attempts("invitations:user:{$test->admin->id}"))->toBe(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
}

test('the owner and an admin can still revoke and resend', function (string $who) {
    $this->actingAs($who === 'owner' ? $this->owner : $this->admin);
    $other = OrganizationInvite::factory()->create([
        'organization_id' => $this->acme->id,
        'email' => 'revoke-me@example.com',
        'invited_by' => $this->owner->id,
    ]);

    Livewire::test(InvitationsList::class)
        ->assertOk()
        ->callAction(TestAction::make('revoke')->table($other))
        ->assertNotified('Invitation revoked');

    $oldHash = $this->invite->token_hash;

    Livewire::test(InvitationsList::class)
        ->callAction(TestAction::make('resend')->table($this->invite))
        ->assertNotified('Invitation resent');

    expect($other->fresh()->status)->toBe(InviteStatus::Revoked)
        ->and($this->invite->fresh()->token_hash)->not->toBe($oldHash);
    Mail::assertQueuedCount(1);
    Mail::assertQueued(OrganizationInvitationMail::class, fn ($mail) => $mail->hasTo('pending@example.com'));
})->with(['owner', 'admin']);

test('a member or a viewer cannot open the list', function (OrganizationRole $role) {
    $this->actingAs(invitationsAuthUser($this->acme, 'low@acme.test', $role));

    Livewire::test(InvitationsList::class)->assertForbidden();
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

test('a signed-in user who is not a member of the organization cannot open the list', function () {
    $this->actingAs(User::factory()->onboardingCompleted()->create(['email' => 'stranger@example.com']));

    Livewire::test(InvitationsList::class)->assertForbidden();
});

test('nobody can open the list without a current organization', function () {
    Filament::setTenant(null);
    $this->actingAs($this->owner);

    Livewire::test(InvitationsList::class)->assertForbidden();
});

test('an admin demoted with the modal open can no longer revoke or resend', function (OrganizationRole $role, string $action, bool $debug) {
    config(['app.debug' => $debug]);
    $this->actingAs($this->admin);

    $component = Livewire::test(InvitationsList::class)->assertOk();

    $this->acme->members()->updateExistingPivot($this->admin->id, ['role' => $role->value]);

    $component->call('mountAction', $action, [], ['table' => true, 'recordKey' => (string) $this->invite->id])
        ->assertForbidden();

    invitationsAuthAssertUntouched($this);
})->with([
    'viewer revoke' => [OrganizationRole::Viewer, 'revoke', false],
    'viewer resend' => [OrganizationRole::Viewer, 'resend', false],
    'member revoke' => [OrganizationRole::Member, 'revoke', true],
    'member resend' => [OrganizationRole::Member, 'resend', true],
]);

test('an admin removed from the organization with the modal open is refused', function () {
    $this->actingAs($this->admin);

    $refreshing = Livewire::test(InvitationsList::class)->assertOk();
    $component = Livewire::test(InvitationsList::class)->assertOk();

    $this->acme->members()->detach($this->admin->id);

    $refreshing->call('$refresh')->assertForbidden();
    $component->call('mountAction', 'revoke', [], ['table' => true, 'recordKey' => (string) $this->invite->id])
        ->assertForbidden();

    invitationsAuthAssertUntouched($this);
});

test('a forged callMountedAction after demotion is refused', function () {
    $this->actingAs($this->admin);

    $component = Livewire::test(InvitationsList::class)->assertOk();

    $this->acme->members()->updateExistingPivot($this->admin->id, ['role' => OrganizationRole::Viewer->value]);

    $component->call('callMountedAction')->assertForbidden();

    invitationsAuthAssertUntouched($this);
});

test('the actions are hidden by their own authorization', function (string $action) {
    $this->actingAs($this->admin);

    $component = Livewire::test(InvitationsList::class)->assertOk();

    $component->assertActionVisible(TestAction::make($action)->table($this->invite));

    $this->acme->members()->updateExistingPivot($this->admin->id, ['role' => OrganizationRole::Viewer->value]);

    $component->assertActionHidden(TestAction::make($action)->table($this->invite));
})->with(['revoke', 'resend']);
