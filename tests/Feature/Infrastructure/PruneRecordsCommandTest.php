<?php

use App\Enums\InviteStatus;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 03:20:00 UTC'));

    $this->makeNotification = function (User $user, ?string $readAt): DatabaseNotification {
        return DatabaseNotification::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->getKey(),
            'data' => [],
            'read_at' => $readAt === null ? null : CarbonImmutable::parse($readAt),
            'created_at' => CarbonImmutable::parse('2026-09-01 00:00:00 UTC'),
            'updated_at' => CarbonImmutable::parse('2026-09-01 00:00:00 UTC'),
        ]);
    };

    $this->makeInvite = function (InviteStatus $status, string $updatedAt, ?Organization $organization = null): OrganizationInvite {
        return OrganizationInvite::factory()->create(array_filter([
            'organization_id' => $organization?->getKey(),
            'email' => 'invitee@example.com',
            'status' => $status,
            'expires_at' => CarbonImmutable::parse('2026-09-08 00:00:00 UTC'),
            'created_at' => CarbonImmutable::parse('2026-09-01 00:00:00 UTC'),
            'updated_at' => CarbonImmutable::parse($updatedAt),
        ], fn ($value) => $value !== null));
    };
});

it('deletes only notifications read more than 7 days ago', function (?string $readAt, bool $exists) {
    $notification = ($this->makeNotification)(User::factory()->create(), $readAt);

    $this->artisan('records:prune')->assertSuccessful();

    expect(DatabaseNotification::query()->whereKey($notification->getKey())->exists())->toBe($exists);
})->with([
    'read 1 second past 7 days' => ['2026-11-09 03:19:59 UTC', false],
    'read exactly 7 days ago' => ['2026-11-09 03:20:00 UTC', true],
    'read yesterday' => ['2026-11-15 10:00:00 UTC', true],
    'never read, however old' => [null, true],
]);

it('prunes notifications of every user and organization', function () {
    $userA = User::factory()->withPersonalOrganization()->create();
    $userB = User::factory()->withPersonalOrganization()->create();
    ($this->makeNotification)($userA, '2026-11-01 00:00:00 UTC');
    ($this->makeNotification)($userB, '2026-11-01 00:00:00 UTC');
    $unread = ($this->makeNotification)($userA, null);

    $this->artisan('records:prune')->expectsOutputToContain('Deleted 2 read notifications.')->assertSuccessful();

    expect(DatabaseNotification::query()->pluck('id')->all())->toBe([$unread->getKey()]);
});

it('deletes only finished invitations updated more than 7 days ago', function (InviteStatus $status, string $updatedAt, bool $exists) {
    $invite = ($this->makeInvite)($status, $updatedAt);

    $this->artisan('records:prune')->assertSuccessful();

    expect(OrganizationInvite::query()->whereKey($invite->getKey())->exists())->toBe($exists);
})->with([
    'accepted, 1 second past 7 days' => [InviteStatus::Accepted, '2026-11-09 03:19:59 UTC', false],
    'revoked, 1 second past 7 days' => [InviteStatus::Revoked, '2026-11-09 03:19:59 UTC', false],
    'expired, 1 second past 7 days' => [InviteStatus::Expired, '2026-11-09 03:19:59 UTC', false],
    'accepted, exactly 7 days' => [InviteStatus::Accepted, '2026-11-09 03:20:00 UTC', true],
    'revoked, 4 days ago' => [InviteStatus::Revoked, '2026-11-12 00:00:00 UTC', true],
    'pending, long past expires_at' => [InviteStatus::Pending, '2026-09-01 00:00:00 UTC', true],
]);

it('prunes finished invitations of every organization and keeps the pending ones', function () {
    $acme = Organization::factory()->create();
    $globex = Organization::factory()->create();
    ($this->makeInvite)(InviteStatus::Accepted, '2026-10-01 00:00:00 UTC', $acme);
    ($this->makeInvite)(InviteStatus::Revoked, '2026-10-01 00:00:00 UTC', $globex);
    $pending = ($this->makeInvite)(InviteStatus::Pending, '2026-10-01 00:00:00 UTC', $globex);

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

    $this->artisan('records:prune')
        ->expectsOutputToContain('Deleted 1 read notification.')
        ->expectsOutputToContain('Deleted 2 finished invitations.')
        ->assertSuccessful();

    $this->artisan('records:prune')
        ->expectsOutputToContain('Deleted 0 read notifications.')
        ->expectsOutputToContain('Deleted 0 finished invitations.')
        ->assertSuccessful();
});
