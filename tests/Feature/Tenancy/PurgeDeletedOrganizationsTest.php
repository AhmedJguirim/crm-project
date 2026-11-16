<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Support\UserFiles;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * SEC-51: organizations:purge removes an organization for good 30 days after its owner deleted it.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->acme = Organization::factory()->create(['name' => 'Acme Studio', 'created_by' => $this->olga->id, 'logo_path' => 'organization-logos/acme.png']);
    $this->acme->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);

    $this->globex = Organization::factory()->create(['name' => 'Globex', 'created_by' => $this->olga->id, 'logo_path' => 'organization-logos/globex.png', 'created_at' => '2025-01-01 00:00:00']);
    $this->globex->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);

    $this->tables = seedOrganizationRows($this->acme);
    $this->globexTables = seedOrganizationRows($this->globex);

    Storage::disk('local')->put('organization-logos/acme.png', 'x');
    Storage::disk('local')->put('organization-logos/globex.png', 'x');
    $this->acmeExport = UserFiles::exportPath('contacts', $this->acme->id, $this->olga->id, 'csv');
    $this->globexExport = UserFiles::exportPath('contacts', $this->globex->id, $this->olga->id, 'csv');
    Storage::disk('local')->put($this->acmeExport, 'x');
    Storage::disk('local')->put($this->globexExport, 'x');

    $this->travelTo('2026-11-01 09:00:00');
    $this->acme->delete();
});

it('keeps the organization until 30 days have passed to the second', function () {
    $before = organizationRowCounts($this->acme, $this->tables);

    $this->travelTo('2026-12-01 08:59:59');

    $this->artisan('organizations:purge')->expectsOutputToContain('Purged 0 organizations.')->assertSuccessful();

    expect(Organization::onlyTrashed()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and(organizationRowCounts($this->acme, $this->tables))->toBe($before)
        ->and(Storage::disk('local')->exists('organization-logos/acme.png'))->toBeTrue()
        ->and(Storage::disk('local')->exists($this->acmeExport))->toBeTrue();
});

it('purges it with every row, its logo and its files at exactly 30 days', function () {
    $this->travelTo('2026-12-01 09:00:00');
    $globexBefore = organizationRowCounts($this->globex, $this->globexTables);
    $log = fakeSecurityLog();

    $this->artisan('organizations:purge')->expectsOutputToContain('Purged 1 organization.')->assertSuccessful();

    expect(Organization::withTrashed()->whereKey($this->acme->id)->exists())->toBeFalse()
        ->and(array_sum(organizationRowCounts($this->acme, $this->tables)))->toBe(0)
        ->and(Storage::disk('local')->exists('organization-logos/acme.png'))->toBeFalse()
        ->and(Storage::disk('local')->exists($this->acmeExport))->toBeFalse()
        ->and(Storage::disk('local')->exists('organization-logos/globex.png'))->toBeTrue()
        ->and(Storage::disk('local')->exists($this->globexExport))->toBeTrue()
        ->and(organizationRowCounts($this->globex, $this->globexTables))->toBe($globexBefore)
        ->and(User::query()->whereKey($this->olga->id)->exists())->toBeTrue();

    $purged = collect(securityLogEntries($log))->firstWhere('event', 'organization.purged');

    expect($purged['level'])->toBe('warning')
        ->and($purged['context'])->toMatchArray(['deleted_organization_id' => $this->acme->id]);
});

it('never purges a live organization, however old', function () {
    $this->travelTo('2026-12-01 09:00:00');
    $before = organizationRowCounts($this->globex, $this->globexTables);

    $this->artisan('organizations:purge')->assertSuccessful();

    expect(Organization::query()->whereKey($this->globex->id)->exists())->toBeTrue()
        ->and(organizationRowCounts($this->globex, $this->globexTables))->toBe($before);
});

it('purges several organizations and logs each one', function () {
    $other = Organization::factory()->create(['created_by' => $this->olga->id]);
    $other->delete();
    $this->travelTo('2026-12-01 09:00:00');
    $log = fakeSecurityLog();

    $this->artisan('organizations:purge')->expectsOutputToContain('Purged 2 organizations.')->assertSuccessful();

    expect(collect(securityLogEntries($log))->where('event', 'organization.purged')->pluck('context.deleted_organization_id')->sort()->values()->all())
        ->toBe(collect([$this->acme->id, $other->id])->sort()->values()->all());
});

it('keeps an organization restored after the purge built its list', function () {
    $this->travelTo('2026-12-01 09:00:00');
    $before = organizationRowCounts($this->acme, $this->tables);
    $log = fakeSecurityLog();
    $restored = false;

    DB::listen(function (QueryExecuted $query) use (&$restored): void {
        if ($restored || ! str_contains($query->sql, 'from "organizations"') || ! str_contains($query->sql, 'deleted_at')) {
            return;
        }

        $restored = true;
        DB::table('organizations')->where('id', $this->acme->id)->update(['deleted_at' => null]);
    });

    $this->artisan('organizations:purge')->expectsOutputToContain('Purged 0 organizations.')->assertSuccessful();

    $fresh = Organization::query()->find($this->acme->id);

    expect($restored)->toBeTrue()
        ->and($fresh)->not->toBeNull()
        ->and($fresh->deleted_at)->toBeNull()
        ->and(organizationRowCounts($this->acme, $this->tables))->toBe($before)
        ->and(Storage::disk('local')->exists('organization-logos/acme.png'))->toBeTrue()
        ->and(collect(securityLogEntries($log))->where('event', 'organization.purged'))->toBeEmpty();
});

it('keeps an organization restored and deleted again after the purge built its list', function () {
    $this->travelTo('2026-12-01 09:00:00');
    $before = organizationRowCounts($this->acme, $this->tables);
    $log = fakeSecurityLog();
    $moved = false;

    DB::listen(function (QueryExecuted $query) use (&$moved): void {
        if ($moved || ! str_contains($query->sql, 'from "organizations"') || ! str_contains($query->sql, 'deleted_at')) {
            return;
        }

        $moved = true;
        DB::table('organizations')->where('id', $this->acme->id)->update(['deleted_at' => now()]);
    });

    $this->artisan('organizations:purge')->expectsOutputToContain('Purged 0 organizations.')->assertSuccessful();

    expect($moved)->toBeTrue()
        ->and(Organization::onlyTrashed()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and(organizationRowCounts($this->acme, $this->tables))->toBe($before)
        ->and(collect(securityLogEntries($log))->where('event', 'organization.purged'))->toBeEmpty();
});

it('is scheduled daily at 03:25 without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'organizations:purge'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('25 3 * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
