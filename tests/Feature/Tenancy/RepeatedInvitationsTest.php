<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Livewire\Tenancy\InvitationsList;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Support\Tenancy\InvitationLimits;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function () {
    Mail::fake();

    $this->olga = User::factory()->onboardingCompleted()->create(['email' => 'olga@example.com']);
    $this->acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $this->olga->id]);
    $this->acme->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);
    $this->globex = Organization::factory()->create(['name' => 'Globex']);

    $this->actingAs($this->olga);
    Filament::setTenant($this->acme);
});

function repeatedInvite(string $email, OrganizationRole $role = OrganizationRole::Member)
{
    return Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('inviteMember')->table(), ['email' => $email, 'role' => $role->value]);
}

function repeatedInviteFor(Organization $organization, string $email, string $state = 'pending', array $attributes = []): OrganizationInvite
{
    $factory = $state === 'pending' ? OrganizationInvite::factory() : OrganizationInvite::factory()->{$state}();

    return $factory->create([
        'organization_id' => $organization->id,
        'email' => $email,
        'invited_by' => $organization->created_by,
        ...$attributes,
    ]);
}

function repeatedInvitesCount(Organization $organization, string $email, ?InviteStatus $status = null): int
{
    return $organization->invitations()
        ->whereEmailIgnoringCase($email)
        ->when($status, fn ($query) => $query->where('status', $status))
        ->count();
}

function repeatedMember(Organization $organization, string $email, OrganizationRole $role = OrganizationRole::Member): User
{
    $user = User::factory()->onboardingCompleted()->create(['email' => $email]);
    $organization->members()->attach($user->id, ['role' => $role->value]);

    return $user;
}

function repeatedPivot(Organization $organization, User $user): OrganizationUser
{
    return OrganizationUser::query()
        ->where('organization_id', $organization->id)
        ->where('user_id', $user->id)
        ->sole();
}

test('a removed member is invited again and rejoins', function () {
    $bob = repeatedMember($this->acme, 'bob@example.com');
    repeatedInviteFor($this->acme, 'bob@example.com', 'accepted');

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('removeMember')->table(repeatedPivot($this->acme, $bob)))
        ->assertNotified('Member removed');

    expect($this->acme->hasMember($bob))->toBeFalse()
        ->and(repeatedInvitesCount($this->acme, 'bob@example.com'))->toBe(0);

    repeatedInvite('bob@example.com', OrganizationRole::Viewer)->assertNotified('Invitation sent');

    $token = null;
    Mail::assertQueued(OrganizationInvitationMail::class, function (OrganizationInvitationMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });
    Mail::assertQueuedCount(1);

    $this->actingAs($bob)
        ->post(route('invite.accept.store', $token))
        ->assertRedirect(Filament::getUrl($this->acme))
        ->assertSessionHas('status', 'Welcome to Acme!');

    expect($this->acme->fresh()->getMemberRole($bob))->toBe(OrganizationRole::Viewer);
});

test('rejoining works even when an old accepted row is still there', function () {
    $bob = User::factory()->onboardingCompleted()->create(['email' => 'bob@example.com']);
    repeatedInviteFor($this->acme, 'Bob@Example.com', 'accepted');
    repeatedInviteFor($this->acme, 'bob@example.com', 'pending', ['role' => OrganizationRole::Member])
        ->forceFill(['token_hash' => OrganizationInvite::hashToken($token = str_repeat('b', 64))])->save();

    $this->actingAs($bob)
        ->post(route('invite.accept.store', $token))
        ->assertRedirect();

    expect($this->acme->hasMember($bob))->toBeTrue()
        ->and(repeatedInvitesCount($this->acme, 'bob@example.com', InviteStatus::Accepted))->toBe(2);
});

test('removing a member keeps other addresses and other organizations', function () {
    $bob = repeatedMember($this->acme, 'bob@example.com');
    repeatedInviteFor($this->acme, 'bob@example.com', 'accepted');
    $erin = repeatedInviteFor($this->acme, 'erin@example.com');
    $globex = repeatedInviteFor($this->globex, 'bob@example.com');
    $other = repeatedInviteFor($this->acme, 'bobby@example.com');

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('removeMember')->table(repeatedPivot($this->acme, $bob)))
        ->assertNotified('Member removed');

    expect($this->acme->hasMember($bob))->toBeFalse()
        ->and(repeatedInvitesCount($this->acme, 'bob@example.com'))->toBe(0)
        ->and($erin->fresh())->not->toBeNull()
        ->and($other->fresh())->not->toBeNull()
        ->and($globex->fresh())->not->toBeNull()
        ->and($globex->fresh()->status)->toBe(InviteStatus::Pending);
});

test('removing a member deletes their other-case invitation rows too and the old link stays dead', function () {
    $bob = repeatedMember($this->acme, 'bob@example.com');
    $invite = repeatedInviteFor($this->acme, 'Bob@Example.com', 'accepted');
    $invite->forceFill(['token_hash' => OrganizationInvite::hashToken($token = str_repeat('t', 64))])->save();

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('removeMember')->table(repeatedPivot($this->acme, $bob)));

    expect(repeatedInvitesCount($this->acme, 'bob@example.com'))->toBe(0);

    $this->actingAs($bob)
        ->get(route('invite.accept', $token))
        ->assertRedirect(Filament::getLoginUrl());

    expect(collect(session('filament.notifications'))->pluck('body')->all())->toContain('This invitation link is invalid.')
        ->and($this->acme->hasMember($bob))->toBeFalse();
});

test('revoking a second invitation to the same address works', function () {
    repeatedInviteFor($this->acme, 'carol@example.com', 'revoked');
    $pending = repeatedInviteFor($this->acme, 'carol@example.com');

    Livewire::test(InvitationsList::class)
        ->callAction(TestAction::make('revoke')->table($pending))
        ->assertNotified('Invitation revoked');

    expect(repeatedInvitesCount($this->acme, 'carol@example.com', InviteStatus::Revoked))->toBe(2);
});

test('a second invitation expiring on the public link is not a 500', function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00:00', 'UTC'));
    repeatedInviteFor($this->acme, 'dave@example.com', 'expired');
    $pending = repeatedInviteFor($this->acme, 'dave@example.com', 'pending', ['expires_at' => Carbon::parse('2026-11-01 09:00:00', 'UTC')]);
    $pending->forceFill(['token_hash' => OrganizationInvite::hashToken($token = str_repeat('d', 64))])->save();

    auth()->logout();

    $this->get(route('invite.accept', $token))->assertRedirect(Filament::getLoginUrl());

    expect(collect(session('filament.notifications'))->pluck('body')->all())
        ->toContain('This invitation has expired. Please request a new invite from the organization admin.')
        ->and(repeatedInvitesCount($this->acme, 'dave@example.com', InviteStatus::Expired))->toBe(2);
});

test('a stale pending invitation does not block a new one', function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00:00', 'UTC'));
    $stale = repeatedInviteFor($this->acme, 'erin@example.com', 'pending', ['expires_at' => Carbon::parse('2026-11-01 09:00:00', 'UTC')]);

    repeatedInvite('Erin@Example.com')->assertNotified('Invitation sent');

    $pending = $this->acme->invitations()->where('status', InviteStatus::Pending)->whereEmailIgnoringCase('erin@example.com')->get();

    expect($stale->fresh()->status)->toBe(InviteStatus::Expired)
        ->and($pending)->toHaveCount(1)
        ->and($pending->first()->expires_at->equalTo(Carbon::parse('2026-11-19 10:00:00', 'UTC')))->toBeTrue();
    Mail::assertQueuedCount(1);
});

test('an invitation that has not expired yet still blocks a new one', function () {
    $live = repeatedInviteFor($this->acme, 'erin@example.com');

    repeatedInvite('erin@example.com')->assertNotified('Invite already pending');

    expect($live->fresh()->status)->toBe(InviteStatus::Pending)
        ->and(repeatedInvitesCount($this->acme, 'erin@example.com'))->toBe(1)
        ->and(RateLimiter::attempts("invitations:organization:{$this->acme->id}"))->toBe(0);
    Mail::assertNothingQueued();
});

test('the stale-expiry only touches pending rows of this organization and this address', function () {
    $this->travelTo(Carbon::parse('2026-11-12 10:00:00', 'UTC'));
    $past = ['expires_at' => Carbon::parse('2026-11-01 09:00:00', 'UTC')];
    $otherAddress = repeatedInviteFor($this->acme, 'zed@example.com', 'pending', $past);
    $otherOrganization = repeatedInviteFor($this->globex, 'erin@example.com', 'pending', $past);
    $accepted = repeatedInviteFor($this->acme, 'erin@example.com', 'accepted', $past);
    $revoked = repeatedInviteFor($this->acme, 'erin@example.com', 'revoked', $past);

    repeatedInvite('erin@example.com')->assertNotified('Invitation sent');

    expect($accepted->fresh()->status)->toBe(InviteStatus::Accepted)
        ->and($revoked->fresh()->status)->toBe(InviteStatus::Revoked)
        ->and($otherAddress->fresh()->status)->toBe(InviteStatus::Pending)
        ->and($otherOrganization->fresh()->status)->toBe(InviteStatus::Pending);
});

test('the database allows only one pending invitation per organization and address', function (string $existingStatus, string $organization, string $new, bool $throws) {
    repeatedInviteFor($this->acme, 'carol@example.com', $existingStatus);
    $target = $organization === 'Acme' ? $this->acme : $this->globex;

    $insert = fn () => DB::transaction(fn () => repeatedInviteFor($target, $new, 'pending'));

    if ($throws) {
        expect($insert)->toThrow(UniqueConstraintViolationException::class);

        return;
    }

    expect($insert)->not->toThrow(UniqueConstraintViolationException::class);
})->with([
    'pending, same address, other case' => ['pending', 'Acme', 'Carol@Example.com', true],
    'pending, same address' => ['pending', 'Acme', 'carol@example.com', true],
    'pending, other organization' => ['pending', 'Globex', 'carol@example.com', false],
    'accepted' => ['accepted', 'Acme', 'carol@example.com', false],
    'revoked' => ['revoked', 'Acme', 'carol@example.com', false],
    'expired' => ['expired', 'Acme', 'carol@example.com', false],
]);

test('two accepted, two revoked and two expired rows for one address can coexist', function () {
    foreach (['accepted', 'revoked', 'expired'] as $state) {
        repeatedInviteFor($this->acme, 'carol@example.com', $state);
        repeatedInviteFor($this->acme, 'carol@example.com', $state);
    }

    expect(repeatedInvitesCount($this->acme, 'carol@example.com'))->toBe(6);
});

test('two admins inviting the same address at the same moment is one pending row, no mail and the claim given back', function () {
    $raced = false;
    $acme = $this->acme;
    $owner = $this->olga;

    DB::listen(function (QueryExecuted $query) use (&$raced, $acme, $owner): void {
        if ($raced || ! str_contains($query->sql, 'select exists') || ! str_contains($query->sql, 'from "organization_invites"') || ! in_array('frank@example.com', $query->bindings, true)) {
            return;
        }

        $raced = true;
        DB::table('organization_invites')->insert([
            'organization_id' => $acme->id,
            'email' => 'frank@example.com',
            'token_hash' => OrganizationInvite::hashToken(str_repeat('f', 64)),
            'role' => OrganizationRole::Member->value,
            'status' => InviteStatus::Pending->value,
            'invited_by' => $owner->id,
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    repeatedInvite('frank@example.com')->assertNotified('Invite already pending');

    expect($raced)->toBeTrue()
        ->and(repeatedInvitesCount($this->acme, 'frank@example.com', InviteStatus::Pending))->toBe(1)
        ->and(OrganizationInvite::query()->where('token_hash', OrganizationInvite::hashToken(str_repeat('f', 64)))->count())->toBe(1)
        ->and(RateLimiter::attempts("invitations:organization:{$this->acme->id}"))->toBe(0)
        ->and(RateLimiter::attempts("invitations:user:{$this->olga->id}"))->toBe(0);
    Mail::assertNothingQueued();
});

test('InvitationLimits::release gives back one claim', function () {
    expect(InvitationLimits::claim($this->acme, $this->olga))->toBeNull()
        ->and(InvitationLimits::claim($this->acme, $this->olga))->toBeNull()
        ->and(RateLimiter::attempts("invitations:organization:{$this->acme->id}"))->toBe(2);

    InvitationLimits::release($this->acme, $this->olga);

    expect(RateLimiter::attempts("invitations:organization:{$this->acme->id}"))->toBe(1)
        ->and(RateLimiter::attempts("invitations:user:{$this->olga->id}"))->toBe(1);
});
