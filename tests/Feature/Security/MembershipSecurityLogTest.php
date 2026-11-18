<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Http\Controllers\InviteAcceptController;
use App\Livewire\Tenancy\InvitationsList;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Support\Tenancy\InvitationLimits;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

/**
 * SEC-19: invitations, role changes, removals, leaving and ownership transfers reach the security log, ids and roles only.
 */
function membershipLogSetup(): object
{
    $olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'olga-sec19@example.com']);
    $acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $olga->id]);
    $acme->members()->attach($olga->id, ['role' => OrganizationRole::Owner->value]);

    $people = ['olga' => $olga];

    foreach (['ann' => OrganizationRole::Admin, 'bob' => OrganizationRole::Member, 'vera' => OrganizationRole::Viewer] as $name => $role) {
        $people[$name] = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => "{$name}-sec19@example.com"]);
        $acme->members()->attach($people[$name]->id, ['role' => $role->value]);
    }

    return (object) ['acme' => $acme, 'people' => $people];
}

function membershipLogPivot(Organization $organization, User $user): OrganizationUser
{
    return OrganizationUser::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->sole();
}

/**
 * @return list<array{event: string, level: string, context: array<string, mixed>}>
 */
function membershipLogNamed(TestHandler $log, string $event): array
{
    return array_values(array_filter(securityLogEntries($log), fn (array $entry): bool => $entry['event'] === $event));
}

function membershipLogContexts(TestHandler $log): string
{
    return json_encode(array_column(securityLogEntries($log), 'context'), JSON_THROW_ON_ERROR);
}

function membershipLogPendingInvite(Organization $organization, User $inviter, string $email): OrganizationInvite
{
    return OrganizationInvite::factory()->withToken(Str::random(64))->create([
        'organization_id' => $organization->id,
        'email' => $email,
        'invited_by' => $inviter->id,
    ]);
}

beforeEach(function () {
    $this->log = fakeSecurityLog();
    Mail::fake();
    $this->setup = membershipLogSetup();
    $this->people = $this->setup->people;
    $this->acme = $this->setup->acme;
});

function membershipLogSignIn(object $test, string $who): void
{
    $test->actingAs($test->people[$who]);
    Filament::setTenant($test->acme);
}

test('an invitation sent is logged without the address', function () {
    membershipLogSignIn($this, 'ann');

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('inviteMember')->table(), ['email' => 'newcomer@example.com', 'role' => OrganizationRole::Member->value])
        ->assertHasNoActionErrors();

    $invite = OrganizationInvite::query()->where('email', 'newcomer@example.com')->sole();
    $lines = membershipLogNamed($this->log, 'organization.invitation_sent');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('info')
        ->and($lines[0]['context'])->toBe([
            'invite_id' => $invite->id,
            'role' => 'member',
            'user_id' => $this->people['ann']->id,
            'organization_id' => $this->acme->id,
            'ip' => '127.0.0.1',
        ])
        ->and(membershipLogContexts($this->log))->not->toContain('@')->not->toContain('newcomer');
});

test('a refused invitation logs nothing', function (string $email, string $title) {
    membershipLogSignIn($this, 'ann');
    membershipLogPendingInvite($this->acme, $this->people['olga'], 'pending@example.com');

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('inviteMember')->table(), ['email' => $email, 'role' => OrganizationRole::Member->value])
        ->assertNotified($title);

    expect(membershipLogNamed($this->log, 'organization.invitation_sent'))->toBe([])
        ->and(OrganizationInvite::query()->where('organization_id', $this->acme->id)->count())->toBe(1);
})->with([
    'already a member' => ['bob-sec19@example.com', 'Already a member'],
    'already pending' => ['pending@example.com', 'Invite already pending'],
]);

test('an invitation refused by the daily cap logs nothing', function () {
    membershipLogSignIn($this, 'ann');
    RateLimiter::increment("invitations:organization:{$this->acme->id}", InvitationLimits::WINDOW_SECONDS, InvitationLimits::ORGANIZATION_PER_DAY);

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('inviteMember')->table(), ['email' => 'capped@example.com', 'role' => OrganizationRole::Member->value])
        ->assertNotified('Too many invitations');

    expect(OrganizationInvite::query()->where('email', 'capped@example.com')->exists())->toBeFalse()
        ->and(membershipLogNamed($this->log, 'organization.invitation_sent'))->toBe([]);
});

test('revoke and resend are logged', function () {
    membershipLogSignIn($this, 'ann');
    $later = membershipLogPendingInvite($this->acme, $this->people['olga'], 'later@example.com');
    $again = membershipLogPendingInvite($this->acme, $this->people['olga'], 'again@example.com');

    Livewire::test(InvitationsList::class)->callAction(TestAction::make('revoke')->table($later));

    $revoked = membershipLogNamed($this->log, 'organization.invitation_revoked');
    expect($revoked)->toHaveCount(1)
        ->and($revoked[0]['level'])->toBe('info')
        ->and($revoked[0]['context']['invite_id'])->toBe($later->id)
        ->and($revoked[0]['context']['user_id'])->toBe($this->people['ann']->id)
        ->and($later->fresh()->status)->toBe(InviteStatus::Revoked);

    Livewire::test(InvitationsList::class)->callAction(TestAction::make('resend')->table($again));

    $resent = membershipLogNamed($this->log, 'organization.invitation_resent');
    expect($resent)->toHaveCount(1)
        ->and($resent[0]['level'])->toBe('info')
        ->and($resent[0]['context']['invite_id'])->toBe($again->id)
        ->and($resent[0]['context']['user_id'])->toBe($this->people['ann']->id)
        ->and(membershipLogContexts($this->log))->not->toContain('@');
});

test('a resend refused inside 10 minutes logs nothing', function () {
    membershipLogSignIn($this, 'ann');
    $again = membershipLogPendingInvite($this->acme, $this->people['olga'], 'again@example.com');

    Livewire::test(InvitationsList::class)->callAction(TestAction::make('resend')->table($again));
    Livewire::test(InvitationsList::class)
        ->callAction(TestAction::make('resend')->table($again))
        ->assertNotified('Invitation already resent');

    expect(membershipLogNamed($this->log, 'organization.invitation_resent'))->toHaveCount(1);
});

test('a revoke or resend by a member writes no line', function () {
    membershipLogSignIn($this, 'bob');
    $invite = membershipLogPendingInvite($this->acme, $this->people['olga'], 'later@example.com');

    Livewire::test(InvitationsList::class)->assertForbidden();

    expect(securityLogEntries($this->log))->toBe([])
        ->and($invite->fresh()->status)->toBe(InviteStatus::Pending);
});

test('an accepted invitation is logged once, even when posted twice', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'double-sec19@example.com']);
    $token = Str::random(64);
    $invite = OrganizationInvite::factory()->withToken($token)->create([
        'organization_id' => $this->acme->id,
        'email' => 'double-sec19@example.com',
        'role' => OrganizationRole::Viewer,
        'invited_by' => $this->people['olga']->id,
    ]);

    $this->actingAs($user);
    $this->post(route('invite.accept.store', $token));
    $this->post(route('invite.accept.store', $token));

    $lines = membershipLogNamed($this->log, 'organization.invitation_accepted');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('info')
        ->and($lines[0]['context']['invite_id'])->toBe($invite->id)
        ->and($lines[0]['context']['joined_organization_id'])->toBe($this->acme->id)
        ->and($lines[0]['context']['role'])->toBe('viewer')
        ->and($lines[0]['context']['user_id'])->toBe($user->id)
        ->and($lines[0]['context']['organization_id'])->toBeNull()
        ->and(membershipLogContexts($this->log))->not->toContain('@')->not->toContain($token)->not->toContain(OrganizationInvite::hashToken($token));
});

test('a racing second acceptance of a stale invite writes no second line', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'race-sec19@example.com']);
    $invite = OrganizationInvite::factory()->withToken(Str::random(64))->create([
        'organization_id' => $this->acme->id,
        'email' => 'race-sec19@example.com',
        'invited_by' => $this->people['olga']->id,
    ]);
    $stale = OrganizationInvite::query()->findOrFail($invite->id);

    $controller = app(InviteAcceptController::class);

    expect($controller->acceptInvite($invite, $user))->toBeTrue()
        ->and($controller->acceptInvite($stale, $user))->toBeFalse()
        ->and(membershipLogNamed($this->log, 'organization.invitation_accepted'))->toHaveCount(1);
});

test('an invitation for another address is not accepted and logs nothing', function () {
    $stranger = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'stranger-sec19@example.com']);
    $token = Str::random(64);
    OrganizationInvite::factory()->withToken($token)->create([
        'organization_id' => $this->acme->id,
        'email' => 'someone-sec19@example.com',
        'invited_by' => $this->people['olga']->id,
    ]);

    $this->actingAs($stranger);
    $this->post(route('invite.accept.store', $token));

    expect(membershipLogNamed($this->log, 'organization.invitation_accepted'))->toBe([])
        ->and($this->acme->members()->whereKey($stranger->id)->exists())->toBeFalse();
});

test('a role change is logged with both roles', function () {
    membershipLogSignIn($this, 'olga');

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('changeRole')->table(membershipLogPivot($this->acme, $this->people['bob'])), ['role' => OrganizationRole::Admin->value]);

    $lines = membershipLogNamed($this->log, 'organization.member_role_changed');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('warning')
        ->and($lines[0]['context'])->toBe([
            'member_user_id' => $this->people['bob']->id,
            'from_role' => 'member',
            'to_role' => 'admin',
            'user_id' => $this->people['olga']->id,
            'organization_id' => $this->acme->id,
            'ip' => '127.0.0.1',
        ])
        ->and($this->acme->getMemberRole($this->people['bob']))->toBe(OrganizationRole::Admin);
});

test('saving the same role logs nothing', function () {
    membershipLogSignIn($this, 'olga');

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('changeRole')->table(membershipLogPivot($this->acme, $this->people['bob'])), ['role' => OrganizationRole::Member->value])
        ->assertNotified('Role updated');

    expect(membershipLogNamed($this->log, 'organization.member_role_changed'))->toBe([]);
});

test('a removal is logged with the removed member role', function () {
    membershipLogSignIn($this, 'ann');

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('removeMember')->table(membershipLogPivot($this->acme, $this->people['vera'])));

    $lines = membershipLogNamed($this->log, 'organization.member_removed');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('warning')
        ->and($lines[0]['context'])->toBe([
            'member_user_id' => $this->people['vera']->id,
            'role' => 'viewer',
            'user_id' => $this->people['ann']->id,
            'organization_id' => $this->acme->id,
            'ip' => '127.0.0.1',
        ])
        ->and($this->acme->members()->whereKey($this->people['vera']->id)->exists())->toBeFalse()
        ->and(membershipLogContexts($this->log))->not->toContain('@');
});

test('an ownership transfer is logged', function () {
    membershipLogSignIn($this, 'olga');

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('transferOwnership')->table(), ['new_owner_id' => $this->people['ann']->id]);

    $lines = membershipLogNamed($this->log, 'organization.ownership_transferred');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('warning')
        ->and($lines[0]['context'])->toBe([
            'new_owner_user_id' => $this->people['ann']->id,
            'user_id' => $this->people['olga']->id,
            'organization_id' => $this->acme->id,
            'ip' => '127.0.0.1',
        ])
        ->and($this->acme->getMemberRole($this->people['ann']))->toBe(OrganizationRole::Owner);
});

test('an ownership transfer to a non-member or a forged id logs nothing', function (mixed $newOwnerId) {
    membershipLogSignIn($this, 'olga');
    $outsider = User::factory()->onboardingCompleted()->create(['email' => 'outsider-sec19@example.com']);

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('transferOwnership')->table(), ['new_owner_id' => $newOwnerId === 'outsider' ? $outsider->id : $newOwnerId]);

    expect(membershipLogNamed($this->log, 'organization.ownership_transferred'))->toBe([])
        ->and($this->acme->getMemberRole($this->people['olga']))->toBe(OrganizationRole::Owner)
        ->and($this->acme->members()->whereKey($outsider->id)->exists())->toBeFalse();
})->with(['outsider', ' 5', '9999999999999999999']);

test('leaving is logged with the role held', function () {
    $this->actingAs($this->people['bob']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->acme);

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'Acme')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $lines = membershipLogNamed($this->log, 'organization.member_left');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('info')
        ->and($lines[0]['context'])->toBe([
            'role' => 'member',
            'user_id' => $this->people['bob']->id,
            'organization_id' => $this->acme->id,
            'ip' => '127.0.0.1',
        ])
        ->and($this->acme->members()->whereKey($this->people['bob']->id)->exists())->toBeFalse();
});

test('a wrong leave confirmation logs nothing', function () {
    $this->actingAs($this->people['bob']);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->acme);

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'acme')
        ->call('callMountedAction')
        ->assertHasErrors();

    expect(membershipLogNamed($this->log, 'organization.member_left'))->toBe([])
        ->and($this->acme->members()->whereKey($this->people['bob']->id)->exists())->toBeTrue();
});

test('an admin forging the removal of the owner writes no line', function () {
    membershipLogSignIn($this, 'ann');

    Livewire::test(OrganizationMembers::class)
        ->call('mountAction', 'removeMember', [], ['table' => true, 'recordKey' => (string) membershipLogPivot($this->acme, $this->people['olga'])->getKey()])
        ->assertSet('mountedActions', [])
        ->call('callMountedAction')
        ->assertSet('mountedActions', []);

    expect($this->acme->members()->whereKey($this->people['olga']->id)->exists())->toBeTrue()
        ->and(membershipLogNamed($this->log, 'organization.member_removed'))->toBe([]);
});

test('an admin forging a role change on her own row writes no line', function () {
    membershipLogSignIn($this, 'ann');

    Livewire::test(OrganizationMembers::class)
        ->call('mountAction', 'changeRole', [], ['table' => true, 'recordKey' => (string) membershipLogPivot($this->acme, $this->people['ann'])->getKey()])
        ->assertSet('mountedActions', [])
        ->call('callMountedAction', ['role' => OrganizationRole::Owner->value])
        ->assertSet('mountedActions', []);

    expect($this->acme->getMemberRole($this->people['ann']))->toBe(OrganizationRole::Admin)
        ->and(membershipLogNamed($this->log, 'organization.member_role_changed'))->toBe([]);
});

test('an admin forging the ownership transfer writes no line', function () {
    membershipLogSignIn($this, 'ann');

    Livewire::test(OrganizationMembers::class)
        ->call('mountAction', 'transferOwnership', [], ['table' => true])
        ->assertSet('mountedActions', [])
        ->call('callMountedAction', ['new_owner_id' => $this->people['ann']->id])
        ->assertSet('mountedActions', []);

    expect($this->acme->getMemberRole($this->people['olga']))->toBe(OrganizationRole::Owner)
        ->and(membershipLogNamed($this->log, 'organization.ownership_transferred'))->toBe([]);
});
