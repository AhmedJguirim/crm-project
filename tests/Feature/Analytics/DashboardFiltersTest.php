<?php

use App\Enums\OrganizationRole;
use App\Enums\ReportPeriodPreset;
use App\Filament\Pages\Dashboard;
use App\Models\Organization;
use App\Models\User;
use App\Support\Reports\DashboardFilters;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));

    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Anders']);
    $this->org = $this->alice->personalOrganization();
    $this->org->update(['timezone' => 'Europe/Paris']);
    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Brown']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);
    $this->carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Carol Clark']);

    $this->actingAs($this->alice);
    Filament::setTenant($this->org->refresh());
});

test('forged or junk input falls back to the defaults', function (mixed $filters, ReportPeriodPreset $preset, string $start, string $end, string $owner) {
    $ids = ['alice' => $this->alice->id, 'bob' => $this->bob->id, 'carol' => $this->carol->id, 'none' => null];

    if (is_callable($filters)) {
        $filters = $filters($ids);
    }

    $result = DashboardFilters::fromPageFilters($filters);

    expect($result->period->preset)->toBe($preset)
        ->and($result->period->dates())->toBe([$start, $end])
        ->and($result->ownerId)->toBe($ids[$owner]);
})->with([
    'null' => [null, ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'a string' => ['junk', ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'a preset value' => [['period' => 'last_month'], ReportPeriodPreset::LastMonth, '2026-09-01', '2026-09-30', 'none'],
    'a preset instance' => [['period' => ReportPeriodPreset::LastMonth], ReportPeriodPreset::LastMonth, '2026-09-01', '2026-09-30', 'none'],
    'an unknown preset' => [['period' => 'next_decade'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'an array preset' => [['period' => ['x']], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'a custom range' => [['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-10-31'], ReportPeriodPreset::Custom, '2026-09-01', '2026-10-31', 'none'],
    'a custom range with an impossible date' => [['period' => 'custom', 'from' => '2026-02-30', 'to' => '2026-10-31'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'a custom range with an array date' => [['period' => 'custom', 'from' => ['x'], 'to' => '2026-10-31'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'a custom range without an end' => [['period' => 'custom', 'from' => '2026-09-01'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'me' => [['owner' => 'me'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'alice'],
    'a member id as a string' => [fn (array $ids) => ['owner' => (string) $ids['bob']], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'bob'],
    'a member id as an int' => [fn (array $ids) => ['owner' => $ids['bob']], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'bob'],
    'a user of another organization' => [fn (array $ids) => ['owner' => (string) $ids['carol']], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'an unknown id' => [['owner' => '999999'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'an injection' => [['owner' => '1 OR 1=1'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'an array owner' => [['owner' => ['1']], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'zero' => [['owner' => '0'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'a negative id' => [['owner' => -1], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'an id with a trailing newline' => [fn (array $ids) => ['owner' => $ids['bob']."\n"], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'the largest 19 digit id' => [['owner' => '9999999999999999999'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
    'a huge id' => [['owner' => '99999999999999999999'], ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', 'none'],
]);

test('a member who left is no longer a valid owner filter', function () {
    $this->org->members()->detach($this->bob);

    expect(DashboardFilters::fromPageFilters(['owner' => (string) $this->bob->id])->ownerId)->toBeNull();
});

test('me is kept only while the user is a member of the current organization', function () {
    expect(DashboardFilters::fromPageFilters(['owner' => 'me'])->ownerId)->toBe($this->alice->id);

    Filament::setTenant($this->carol->personalOrganization());

    expect(DashboardFilters::fromPageFilters(['owner' => 'me'])->ownerId)->toBeNull();

    Filament::setTenant(null);

    expect(DashboardFilters::fromPageFilters(['owner' => 'me'])->ownerId)->toBeNull();
});

test('the period follows the given timezone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 23:30:00', 'UTC'));

    expect(DashboardFilters::fromPageFilters(['period' => 'today'], 'Europe/Paris')->period->dates())->toBe(['2026-10-22', '2026-10-22'])
        ->and(DashboardFilters::fromPageFilters(['period' => 'today'], 'UTC')->period->dates())->toBe(['2026-10-21', '2026-10-21']);
});

test('the panel dashboard is the app page with the filter form', function () {
    $this->get(Filament::getUrl($this->org))->assertOk()->assertSeeLivewire(Dashboard::class);

    Livewire::test(Dashboard::class)
        ->assertSee('Period')
        ->assertSee('Owner')
        ->assertFormFieldExists('period', 'filtersForm')
        ->assertFormFieldHidden('from', 'filtersForm')
        ->assertFormFieldHidden('to', 'filtersForm')
        ->fillForm(['period' => ReportPeriodPreset::Custom], 'filtersForm')
        ->assertFormFieldVisible('from', 'filtersForm')
        ->assertFormFieldVisible('to', 'filtersForm')
        ->fillForm(['period' => ReportPeriodPreset::LastMonth], 'filtersForm')
        ->assertFormFieldHidden('from', 'filtersForm');
});

test('the owner field is hidden in a one-member organization', function () {
    $dave = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->actingAs($dave);
    Filament::setTenant($dave->personalOrganization());

    Livewire::test(Dashboard::class)
        ->assertFormFieldVisible('period', 'filtersForm')
        ->assertFormFieldHidden('owner', 'filtersForm');
});

test('the owner options are me and the members by name', function () {
    $options = Livewire::test(Dashboard::class)->assertFormFieldVisible('owner', 'filtersForm')->instance()
        ->getFiltersForm()->getComponent('owner')->getOptions();

    expect($options)->toBe(['me' => 'Me', $this->alice->id => 'Alice Anders', $this->bob->id => 'Bob Brown'])
        ->and(Livewire::test(Dashboard::class)->instance()->getFiltersForm()->getComponent('owner')->getPlaceholder())->toBe('Everyone');
});

test('the filters stored in the session are per organization', function () {
    $globex = Organization::factory()->create();
    $globex->members()->attach($this->alice, ['role' => OrganizationRole::Member->value]);
    $globex->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);

    Livewire::test(Dashboard::class)
        ->set('filters.owner', (string) $this->bob->id)
        ->set('filters.period', ReportPeriodPreset::LastMonth->value);

    expect(session(md5(Dashboard::class).'_filters_'.$this->org->id)['owner'])->toBe((string) $this->bob->id);

    Filament::setTenant($globex);

    $page = Livewire::test(Dashboard::class);

    expect($page->get('filters.owner'))->not->toBe((string) $this->bob->id)
        ->and($page->get('filters.period'))->not->toBe(ReportPeriodPreset::LastMonth->value)
        ->and(session()->has(md5(Dashboard::class).'_filters_'.$globex->id))->toBeTrue();
});

test('forged URL filters never break the page', function (array $filters) {
    $this->get(Dashboard::getUrl(['filters' => $filters], tenant: $this->org))->assertOk();

    Livewire::withQueryParams(['filters' => $filters])->test(Dashboard::class)->assertSuccessful();
})->with([
    'arrays and junk' => [['period' => ['x'], 'from' => ['x'], 'to' => 'junk', 'owner' => ['1']]],
    'impossible date and injection' => [['period' => 'custom', 'from' => '2026-99-99', 'owner' => '1 OR 1=1']],
    'nested arrays' => [['period' => ['a' => ['b' => 'c']], 'owner' => ['a' => ['b']]]],
]);

test('every role uses the filters', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->org);

    $this->get(Filament::getUrl($this->org))->assertOk();

    Livewire::test(Dashboard::class)->assertFormFieldVisible('period', 'filtersForm')->assertFormFieldVisible('owner', 'filtersForm');
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);
