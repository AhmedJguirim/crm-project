<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Livewire\Tenancy\InvitationsList;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 03:20:00 UTC'));

    $this->makeNotification = function (User $user, ?string $readAt, ?string $createdAt = null): DatabaseNotification {
        return DatabaseNotification::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->getKey(),
            'data' => [],
            'read_at' => $readAt === null ? null : CarbonImmutable::parse($readAt),
            'created_at' => CarbonImmutable::parse($createdAt ?? '2026-09-01 00:00:00 UTC'),
            'updated_at' => CarbonImmutable::parse($createdAt ?? '2026-09-01 00:00:00 UTC'),
        ]);
    };

    $this->inviteCount = 0;

    $this->makeInvite = function (InviteStatus $status, string $updatedAt, ?Organization $organization = null, ?string $expiresAt = null): OrganizationInvite {
        return OrganizationInvite::factory()->create(array_filter([
            'organization_id' => $organization?->getKey(),
            'email' => 'invitee'.++$this->inviteCount.'@example.com',
            'status' => $status,
            'expires_at' => CarbonImmutable::parse($expiresAt ?? '2026-09-08 00:00:00 UTC'),
            'created_at' => CarbonImmutable::parse('2026-09-01 00:00:00 UTC'),
            'updated_at' => CarbonImmutable::parse($updatedAt),
        ], fn ($value) => $value !== null));
    };
});

it('deletes only notifications read more than 7 days ago', function (?string $readAt, bool $exists, ?string $createdAt = null) {
    $notification = ($this->makeNotification)(User::factory()->create(), $readAt, $createdAt);

    $this->artisan('records:prune')->assertSuccessful();

    expect(DatabaseNotification::query()->whereKey($notification->getKey())->exists())->toBe($exists);
})->with([
    'read 1 second past 7 days' => ['2026-11-09 03:19:59 UTC', false],
    'read exactly 7 days ago' => ['2026-11-09 03:20:00 UTC', true],
    'read yesterday' => ['2026-11-15 10:00:00 UTC', true],
    'never read, created 1 second past 90 days' => [null, false, '2026-08-18 03:19:59 UTC'],
]);

it('prunes notifications of every user and organization', function () {
    $userA = User::factory()->withPersonalOrganization()->create();
    $userB = User::factory()->withPersonalOrganization()->create();
    ($this->makeNotification)($userA, '2026-11-01 00:00:00 UTC');
    ($this->makeNotification)($userB, '2026-11-01 00:00:00 UTC');
    $unread = ($this->makeNotification)($userA, null, '2026-11-01 00:00:00 UTC');

    $this->artisan('records:prune')->expectsOutputToContain('Deleted 2 read notifications.')->assertSuccessful();

    expect(DatabaseNotification::query()->pluck('id')->all())->toBe([$unread->getKey()]);
});

it('deletes only finished invitations updated more than 7 days ago', function (InviteStatus $status, string $updatedAt, bool $exists, ?string $expiresAt = null) {
    $invite = ($this->makeInvite)($status, $updatedAt, null, $expiresAt);

    $this->artisan('records:prune')->assertSuccessful();

    expect(OrganizationInvite::query()->whereKey($invite->getKey())->exists())->toBe($exists);
})->with([
    'accepted, 1 second past 7 days' => [InviteStatus::Accepted, '2026-11-09 03:19:59 UTC', false],
    'revoked, 1 second past 7 days' => [InviteStatus::Revoked, '2026-11-09 03:19:59 UTC', false],
    'expired, 1 second past 7 days' => [InviteStatus::Expired, '2026-11-09 03:19:59 UTC', false],
    'accepted, exactly 7 days' => [InviteStatus::Accepted, '2026-11-09 03:20:00 UTC', true],
    'revoked, 4 days ago' => [InviteStatus::Revoked, '2026-11-12 00:00:00 UTC', true],
    'pending, updated long ago, expired 4 days ago' => [InviteStatus::Pending, '2026-09-01 00:00:00 UTC', true, '2026-11-12 00:00:00 UTC'],
]);

it('prunes finished invitations of every organization and keeps the pending ones', function () {
    $acme = Organization::factory()->create();
    $globex = Organization::factory()->create();
    ($this->makeInvite)(InviteStatus::Accepted, '2026-10-01 00:00:00 UTC', $acme);
    ($this->makeInvite)(InviteStatus::Revoked, '2026-10-01 00:00:00 UTC', $globex);
    $pending = ($this->makeInvite)(InviteStatus::Pending, '2026-10-01 00:00:00 UTC', $globex, '2026-11-20 00:00:00 UTC');

    $this->artisan('records:prune')->expectsOutputToContain('Deleted 2 finished invitations.')->assertSuccessful();

    expect(OrganizationInvite::query()->pluck('id')->all())->toBe([$pending->getKey()]);
});

it('shows the invalid-link message for the link of a pruned invitation', function () {
    $token = Str::random(64);
    OrganizationInvite::factory()->accepted()->withToken($token)->create([
        'created_at' => CarbonImmutable::parse('2026-10-01 00:00:00 UTC'),
        'updated_at' => now()->subDays(8),
    ]);

    $this->artisan('records:prune')->assertSuccessful();

    $this->get(OrganizationInvite::acceptUrl($token))->assertRedirect();

    expect(collect(session('filament.notifications'))->pluck('body')->all())->toContain('This invitation link is invalid.');
});

it('reports the counts and a second run deletes nothing', function () {
    ($this->makeNotification)(User::factory()->create(), '2026-11-01 00:00:00 UTC');
    ($this->makeInvite)(InviteStatus::Accepted, '2026-11-01 00:00:00 UTC');
    ($this->makeInvite)(InviteStatus::Expired, '2026-11-01 00:00:00 UTC');
    ($this->makeNotification)(User::factory()->create(), null, '2026-07-01 00:00:00 UTC');
    ($this->makeInvite)(InviteStatus::Pending, '2026-10-01 00:00:00 UTC', null, '2026-10-01 00:00:00 UTC');

    $this->artisan('records:prune')
        ->expectsOutputToContain('Deleted 1 read notification.')
        ->expectsOutputToContain('Deleted 2 finished invitations.')
        ->expectsOutputToContain('Deleted 1 unread notification older than 90 days.')
        ->expectsOutputToContain('Deleted 1 expired pending invitation.')
        ->assertSuccessful();

    $this->artisan('records:prune')
        ->expectsOutputToContain('Deleted 0 read notifications.')
        ->expectsOutputToContain('Deleted 0 finished invitations.')
        ->expectsOutputToContain('Deleted 0 unread notifications older than 90 days.')
        ->expectsOutputToContain('Deleted 0 expired pending invitations.')
        ->assertSuccessful();
});

it('deletes unread notifications only after 90 days', function (?string $readAt, string $createdAt, bool $exists) {
    $notification = ($this->makeNotification)(User::factory()->create(), $readAt, $createdAt);

    $this->artisan('records:prune')->assertSuccessful();

    expect(DatabaseNotification::query()->whereKey($notification->getKey())->exists())->toBe($exists);
})->with([
    'unread, 1 second past 90 days' => [null, '2026-08-18 03:19:59 UTC', false],
    'unread, exactly 90 days' => [null, '2026-08-18 03:20:00 UTC', true],
    'unread, 2 weeks old' => [null, '2026-11-01 00:00:00 UTC', true],
    'read yesterday, created long ago' => ['2026-11-15 10:00:00 UTC', '2026-08-01 00:00:00 UTC', true],
]);

it('deletes pending invitations only 7 days after expires_at', function (InviteStatus $status, string $expiresAt, bool $exists) {
    $invite = ($this->makeInvite)($status, '2026-11-15 00:00:00 UTC', null, $expiresAt);

    $this->artisan('records:prune')->assertSuccessful();

    expect(OrganizationInvite::query()->whereKey($invite->getKey())->exists())->toBe($exists);
})->with([
    'pending, 1 second past 7 days of expiry' => [InviteStatus::Pending, '2026-11-09 03:19:59 UTC', false],
    'pending, exactly 7 days past expiry' => [InviteStatus::Pending, '2026-11-09 03:20:00 UTC', true],
    'pending, expired 4 days ago' => [InviteStatus::Pending, '2026-11-12 00:00:00 UTC', true],
    'pending, not expired' => [InviteStatus::Pending, '2026-11-20 00:00:00 UTC', true],
    'revoked, updated 1 day ago' => [InviteStatus::Revoked, '2026-11-01 00:00:00 UTC', true],
]);

it('measures a resent pending invitation from its new expiry', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 00:00:00 UTC'));
    $invite = OrganizationInvite::factory()->create([
        'expires_at' => CarbonImmutable::parse('2026-10-08 00:00:00 UTC'),
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-11-14 00:00:00 UTC'));
    $invite->regenerateToken(7);
    $this->travelTo(CarbonImmutable::parse('2026-11-16 03:20:00 UTC'));

    $this->artisan('records:prune')->expectsOutputToContain('Deleted 0 expired pending invitations.')->assertSuccessful();

    expect(OrganizationInvite::query()->whereKey($invite->getKey())->exists())->toBeTrue();
});

it('prunes unread notifications and expired pending invitations of every user and organization', function () {
    $userA = User::factory()->withPersonalOrganization()->create();
    $userB = User::factory()->withPersonalOrganization()->create();
    ($this->makeNotification)($userA, null, '2026-07-01 00:00:00 UTC');
    ($this->makeNotification)($userB, null, '2026-07-01 00:00:00 UTC');
    $acme = Organization::factory()->create();
    $globex = Organization::factory()->create();
    ($this->makeInvite)(InviteStatus::Pending, '2026-10-01 00:00:00 UTC', $acme, '2026-10-01 00:00:00 UTC');
    ($this->makeInvite)(InviteStatus::Pending, '2026-10-01 00:00:00 UTC', $globex, '2026-10-01 00:00:00 UTC');
    $kept = ($this->makeInvite)(InviteStatus::Pending, '2026-10-01 00:00:00 UTC', $acme, '2026-11-20 00:00:00 UTC');

    $this->artisan('records:prune')
        ->expectsOutputToContain('Deleted 2 unread notifications older than 90 days.')
        ->expectsOutputToContain('Deleted 2 expired pending invitations.')
        ->assertSuccessful();

    expect(DatabaseNotification::query()->count())->toBe(0)
        ->and(OrganizationInvite::query()->pluck('id')->all())->toBe([$kept->getKey()]);
});

it('shows the invalid-link message for the link of a pruned pending invitation', function () {
    $token = Str::random(64);
    OrganizationInvite::factory()->withToken($token)->create([
        'expires_at' => CarbonImmutable::parse('2026-11-01 00:00:00 UTC'),
    ]);

    $this->artisan('records:prune')->assertSuccessful();

    $this->get(OrganizationInvite::acceptUrl($token))->assertRedirect();

    expect(collect(session('filament.notifications'))->pluck('body')->all())->toContain('This invitation link is invalid.');
});

it('refuses quietly a resend or revoke of an invitation pruned since the list was loaded', function (string $action) {
    Mail::fake();
    $owner = User::factory()->onboardingCompleted()->create();
    $acme = Organization::factory()->create(['created_by' => $owner->id]);
    $acme->members()->attach($owner->id, ['role' => OrganizationRole::Owner->value]);
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $acme->id,
        'invited_by' => $owner->id,
        'expires_at' => CarbonImmutable::parse('2026-11-01 00:00:00 UTC'),
    ]);
    $this->actingAs($owner);
    Filament::setTenant($acme);

    $component = Livewire::test(InvitationsList::class)->assertOk();

    $this->artisan('records:prune')->expectsOutputToContain('Deleted 1 expired pending invitation.')->assertSuccessful();

    $component->call('mountAction', $action, [], ['table' => true, 'recordKey' => (string) $invite->id])
        ->assertOk()
        ->assertHasNoErrors()
        ->assertActionNotMounted();

    Mail::assertNothingQueued();
    Mail::assertNothingSent();
    expect(OrganizationInvite::query()->count())->toBe(0);
})->with(['resend', 'revoke']);

it('a second run deletes no unread notification and no pending invitation', function () {
    ($this->makeNotification)(User::factory()->create(), null, '2026-07-01 00:00:00 UTC');
    ($this->makeInvite)(InviteStatus::Pending, '2026-10-01 00:00:00 UTC', null, '2026-10-01 00:00:00 UTC');

    $this->artisan('records:prune')->assertSuccessful();

    $this->artisan('records:prune')
        ->expectsOutputToContain('Deleted 0 unread notifications older than 90 days.')
        ->expectsOutputToContain('Deleted 0 expired pending invitations.')
        ->assertSuccessful();
});

it('measures an unread notification from created_at, not updated_at', function () {
    $notification = ($this->makeNotification)(User::factory()->create(), null, '2026-07-01 00:00:00 UTC');
    $notification->forceFill(['updated_at' => now()->subDay()])->save();

    $this->artisan('records:prune')->assertSuccessful();

    expect(DatabaseNotification::query()->whereKey($notification->getKey())->exists())->toBeFalse();
});
