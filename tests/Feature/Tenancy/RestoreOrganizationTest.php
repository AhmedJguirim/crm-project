<?php

use App\Actions\Account\DeleteUserAccount;
use App\Actions\Organization\DeleteOrganization;
use App\Actions\Organization\RestoreOrganization;
use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Jobs\SyncSegmentMembership;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * SEC-52: the operator restores a deleted organization with organizations:restore during its 30-day grace period.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Olga Owner', 'email' => 'olga@example.com']);
    $this->adam = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'adam@example.com']);
    $this->mia = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'mia@example.com']);

    $this->acme = Organization::factory()->create(['name' => 'Acme Studio', 'created_by' => $this->olga->id]);
    $this->acme->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);
    $this->acme->members()->attach($this->adam->id, ['role' => OrganizationRole::Admin->value]);
    $this->acme->members()->attach($this->mia->id, ['role' => OrganizationRole::Member->value]);

    $this->globex = Organization::factory()->create(['name' => 'Globex', 'created_by' => $this->olga->id]);
    $this->globex->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);

    $this->tables = seedOrganizationRows($this->acme);
    $this->globexTables = seedOrganizationRows($this->globex);
    $this->rowsBefore = organizationRowCounts($this->acme, $this->tables);

    $this->invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->acme->id,
        'email' => 'new@example.com',
        'status' => InviteStatus::Pending,
    ]);

    $this->travelTo('2026-11-01 09:00:00');
    expect(app(DeleteOrganization::class)->handle($this->olga, $this->acme))->toBeNull();
    $this->travelTo('2026-11-10 12:00:00');

    $this->restoredCount = fn (User $user): int => $user->notifications()->get()
        ->filter(fn (DatabaseNotification $notification): bool => $notification->data['title'] === 'Acme Studio was restored')
        ->count();
});

it('restores with --force and brings everything back', function () {
    $this->artisan("organizations:restore {$this->acme->id} --force")
        ->expectsOutputToContain("Restored organization #{$this->acme->id} \"Acme Studio\". 3 members were notified.")
        ->assertExitCode(0);

    $fresh = Organization::query()->find($this->acme->id);

    expect($fresh)->not->toBeNull()
        ->and($fresh->deleted_at)->toBeNull()
        ->and(organizationRowCounts($this->acme, $this->tables))->toBe($this->rowsBefore)
        ->and(User::query()->find($this->olga->id)->roleIn($this->acme->id))->toBe(OrganizationRole::Owner)
        ->and(User::query()->find($this->adam->id)->roleIn($this->acme->id))->toBe(OrganizationRole::Admin)
        ->and(User::query()->find($this->mia->id)->roleIn($this->acme->id))->toBe(OrganizationRole::Member);
});

it('tells every member, the owner included', function () {
    $this->artisan("organizations:restore {$this->acme->id} --force")->assertSuccessful();

    foreach ([$this->olga, $this->adam, $this->mia] as $user) {
        $restored = $user->notifications()->get()->filter(fn ($n) => $n->data['title'] === 'Acme Studio was restored');

        expect($restored)->toHaveCount(1)
            ->and($restored->first()->data['body'])->toBe('This organization was restored. You can use it again.');
    }

    expect($this->adam->notifications()->count())->toBe(2);
});

it('keeps revoked invitations revoked', function () {
    expect($this->invite->fresh()->status)->toBe(InviteStatus::Revoked);

    $this->artisan("organizations:restore {$this->acme->id} --force")->assertSuccessful();

    expect($this->invite->fresh()->status)->toBe(InviteStatus::Revoked);
});

it('syncs the published segments of that organization only', function () {
    Queue::fake();

    $acmeSegment = Segment::query()->forOrganization($this->acme->id)->where('is_published', true)->firstOrFail();
    $draft = Segment::factory()->create(['organization_id' => $this->acme->id, 'is_published' => false]);
    $globexSegment = Segment::query()->forOrganization($this->globex->id)->where('is_published', true)->firstOrFail();

    $this->artisan("organizations:restore {$this->acme->id} --force")->assertSuccessful();

    Queue::assertPushed(SyncSegmentMembership::class, 1);
    Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $acmeSegment->id);
    Queue::assertNotPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => in_array($job->segmentId, [$draft->id, $globexSegment->id], true));
});

it('logs the restore', function () {
    $log = fakeSecurityLog();

    $this->artisan("organizations:restore {$this->acme->id} --force")->assertSuccessful();

    $entries = collect(securityLogEntries($log))->where('event', 'organization.restored');

    expect($entries)->toHaveCount(1)
        ->and($entries->first()['level'])->toBe('warning')
        ->and($entries->first()['context'])->toMatchArray(['restored_organization_id' => $this->acme->id, 'members' => 3, 'user_id' => null]);
});

it('shows what will be restored and restores after a yes', function () {
    $this->artisan("organizations:restore {$this->acme->id}")
        ->expectsConfirmation("Restore organization #{$this->acme->id} \"Acme Studio\", deleted on 2026-11-01 09:00 UTC, with 3 members?", 'yes')
        ->expectsOutputToContain('Restored organization')
        ->assertSuccessful();

    expect(Organization::query()->whereKey($this->acme->id)->exists())->toBeTrue();
});

it('restores nothing when the operator answers no', function () {
    $log = fakeSecurityLog();

    $this->artisan("organizations:restore {$this->acme->id}")
        ->expectsConfirmation("Restore organization #{$this->acme->id} \"Acme Studio\", deleted on 2026-11-01 09:00 UTC, with 3 members?", 'no')
        ->expectsOutputToContain('Nothing was restored.')
        ->assertExitCode(1);

    expect(Organization::onlyTrashed()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and(($this->restoredCount)($this->adam))->toBe(0)
        ->and(collect(securityLogEntries($log))->where('event', 'organization.restored'))->toBeEmpty();
});

it('restores nothing without --force when there is no interaction', function () {
    $output = new BufferedOutput;

    $exit = Artisan::call('organizations:restore', ['id' => (string) $this->acme->id, '--no-interaction' => true], $output);

    expect($exit)->toBe(1)
        ->and($output->fetch())->toContain('Nothing was restored.')
        ->and(Organization::onlyTrashed()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and(($this->restoredCount)($this->mia))->toBe(0);
});

it('answers a sentence to an invalid id and restores nothing', function (string $raw) {
    $this->artisan('organizations:restore', ['id' => $raw, '--force' => true])
        ->expectsOutputToContain('The organization id must be a whole number above 0.')
        ->assertExitCode(1);

    expect(Organization::onlyTrashed()->whereKey($this->acme->id)->exists())->toBeTrue();
})->with(['abc', '0', '-3', '+12', '012', '1.5', ' 12', '12 ', '9223372036854775808', '99999999999999999999']);

it('says so when the organization was purged or never existed', function () {
    $this->travelTo('2026-12-01 09:00:00');
    $this->artisan('organizations:purge')->assertSuccessful();

    $this->artisan("organizations:restore {$this->acme->id} --force")
        ->expectsOutputToContain("No organization #{$this->acme->id} exists: it was never created or has already been purged.")
        ->assertExitCode(1);

    $this->artisan('organizations:restore 999999 --force')
        ->expectsOutputToContain('No organization #999999 exists: it was never created or has already been purged.')
        ->assertExitCode(1);
});

it('refuses a live organization', function () {
    $this->artisan("organizations:restore {$this->globex->id} --force")
        ->expectsOutputToContain("Organization #{$this->globex->id} \"Globex\" is not deleted: there is nothing to restore.")
        ->assertExitCode(1);

    expect($this->olga->notifications()->get()->where('data.title', 'Globex was restored'))->toBeEmpty();
});

it('refuses an organization whose owner deleted their account during the grace period', function () {
    $log = fakeSecurityLog();

    expect(app(DeleteUserAccount::class)->handle($this->olga, fn () => null))->toBeNull();

    $this->artisan("organizations:restore {$this->acme->id} --force")
        ->expectsOutputToContain("Organization #{$this->acme->id} \"Acme Studio\" has no owner any more (the owner deleted their account), so it cannot be restored.")
        ->assertExitCode(1);

    expect(Organization::onlyTrashed()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and(($this->restoredCount)($this->adam))->toBe(0)
        ->and(($this->restoredCount)($this->mia))->toBe(0)
        ->and(collect(securityLogEntries($log))->where('event', 'organization.restored'))->toBeEmpty();
});

it('can restore past 30 days while the purge has not run yet', function () {
    $this->travelTo('2026-12-15 09:00:00');

    $this->artisan("organizations:restore {$this->acme->id} --force")->assertSuccessful();

    expect(Organization::query()->whereKey($this->acme->id)->exists())->toBeTrue();
});

it('exposes the same refusals through the action', function () {
    $action = app(RestoreOrganization::class);

    expect($action->refusalFor($this->globex->id, Organization::withTrashed()->find($this->globex->id)))->toContain('is not deleted')
        ->and($action->refusalFor($this->acme->id, Organization::withTrashed()->find($this->acme->id)))->toBeNull()
        ->and($action->handle($this->acme->id))->toBeNull();
});
