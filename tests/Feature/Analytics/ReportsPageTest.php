<?php

use App\Enums\OrganizationRole;
use App\Enums\ReportPeriodPreset;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Reports;
use App\Filament\Widgets\Reports\LostReasonsWidget;
use App\Filament\Widgets\Reports\SalesResultsWidget;
use App\Filament\Widgets\Reports\StageConversionWidget;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Analytics\TeamActivityFixture;

beforeEach(function () {
    $this->travelTo(TeamActivityFixture::now());
    $this->team = TeamActivityFixture::build();
});

test('every role opens the Reports page', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->team->org->members()->attach($user, ['role' => $role->value]);
    $this->actingAs($user);
    Filament::setTenant($this->team->org);

    $this->get(Reports::getUrl(tenant: $this->team->org))
        ->assertOk()
        ->assertSee('Reports')
        ->assertSeeLivewire(SalesResultsWidget::class)
        ->assertSeeLivewire(LostReasonsWidget::class)
        ->assertSeeLivewire(StageConversionWidget::class)
        ->assertSeeLivewire(TeamActivityWidget::class);
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

test('a user of another organization cannot open the Reports page', function () {
    $this->actingAs($this->team->carol);
    $expected = $this->get(Filament::getUrl($this->team->org))->status();

    expect($expected)->toBeIn([403, 404]);

    $this->get(Reports::getUrl(tenant: $this->team->org))->assertStatus($expected);
});

test('the dashboard stays the home page', function () {
    $this->get(Filament::getUrl($this->team->org))
        ->assertOk()
        ->assertSeeLivewire(Dashboard::class)
        ->assertDontSeeLivewire(Reports::class);

    expect(Reports::getNavigationSort())->toBeGreaterThan(Dashboard::getNavigationSort());
});

test('the page has the shared filter form', function () {
    Livewire::test(Reports::class)
        ->assertFormFieldExists('period', 'filtersForm')
        ->assertFormFieldExists('owner', 'filtersForm')
        ->assertFormFieldHidden('from', 'filtersForm')
        ->assertFormFieldHidden('to', 'filtersForm')
        ->fillForm(['period' => ReportPeriodPreset::Custom], 'filtersForm')
        ->assertFormFieldVisible('from', 'filtersForm')
        ->assertFormFieldVisible('to', 'filtersForm');

    expect(Livewire::test(Reports::class)->instance()->getWidgets())->toBe([SalesResultsWidget::class, LostReasonsWidget::class, StageConversionWidget::class, TeamActivityWidget::class]);
});

test('the Reports filters are remembered separately from the dashboard', function () {
    Livewire::test(Reports::class)->set('filters.period', ReportPeriodPreset::LastMonth->value);

    expect(session(md5(Reports::class).'_filters_'.$this->team->org->id)['period'])->toBe(ReportPeriodPreset::LastMonth->value)
        ->and(session(md5(Dashboard::class).'_filters_'.$this->team->org->id))->toBeNull();
});

test('forged URL filters never break the page', function (array $filters) {
    $this->get(Reports::getUrl(['filters' => $filters], tenant: $this->team->org))->assertOk();

    Livewire::withQueryParams(['filters' => $filters])->test(Reports::class)->assertSuccessful();
})->with([
    'arrays and junk' => [['period' => ['x'], 'from' => ['x'], 'to' => 'junk', 'owner' => ['1']]],
    'impossible date and injection' => [['period' => 'custom', 'from' => '2026-99-99', 'owner' => '1 OR 1=1']],
    'nested arrays' => [['period' => ['a' => ['b' => 'c']], 'owner' => ['a' => ['b']]]],
]);

test('the sidebar links to Reports', function () {
    $this->get(Filament::getUrl($this->team->org))->assertSee(Reports::getUrl(tenant: $this->team->org));
});

test('the page shows three tabs, Sales results first', function () {
    $this->get(Reports::getUrl(tenant: $this->team->org))
        ->assertOk()
        ->assertSeeHtmlInOrder(['Sales results', 'Conversion', 'Team activity'])
        ->assertSeeLivewire(SalesResultsWidget::class)
        ->assertSeeLivewire(LostReasonsWidget::class)
        ->assertSeeLivewire(StageConversionWidget::class)
        ->assertSeeLivewire(TeamActivityWidget::class);
});

test('tab query strings never break the page', function (mixed $tab) {
    $this->get(Reports::getUrl(['tab' => $tab], tenant: $this->team->org))
        ->assertOk()
        ->assertSeeLivewire(SalesResultsWidget::class);
})->with([
    'team activity' => 'team-activity',
    'sales results' => 'sales-results',
    'conversion' => 'conversion',
    'unknown' => 'unknown',
    'array' => [['x']],
    'nested array' => [['a' => ['b']]],
]);

test('the tabs have stable keys and are kept in the query string', function () {
    $this->get(Reports::getUrl(tenant: $this->team->org))
        ->assertOk()
        ->assertSee('isTabPersistedInQueryString: true', false)
        ->assertSee("tabQueryStringKey: 'tab'", false)
        ->assertSee('value="[&quot;sales-results&quot;,&quot;conversion&quot;,&quot;team-activity&quot;]"', false);
});
