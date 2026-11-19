<?php

use App\Enums\Currency;
use App\Enums\DealStatus;
use App\Filament\Widgets\DashboardV2\DealsClosedWidget;
use App\Filament\Widgets\DashboardV2\DealsCreatedWidget;
use App\Filament\Widgets\DealForecastWidget;
use App\Filament\Widgets\KpiOverviewWidget;
use App\Filament\Widgets\Reports\LeadSourcesWidget;
use App\Filament\Widgets\Reports\SalesResultsWidget;
use App\Filament\Widgets\Reports\SalesVelocityWidget;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-11 10:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Tina Tunis']);
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Tnd, 'timezone' => 'UTC']);
    $this->actingAs($this->user);
    Filament::setTenant($this->org->refresh());
    $this->nbsp = "\u{00A0}";

    $this->deal = fn (string $stage, DealStatus $status, string $value, array $extra = [], ?Organization $org = null): Deal => Deal::factory()->inStage($stage)->create([
        'organization_id' => ($org ?? $this->org)->id,
        'created_by' => $this->user->id,
        'owner_id' => $this->user->id,
        'status' => $status,
        'value' => $value,
        'created_at' => '2026-11-02 10:00:00',
        'won_at' => null,
        'lost_at' => null,
        'expected_close_date' => null,
        ...$extra,
    ]);

    ($this->deal)('Won', DealStatus::Won, '1234.5', ['title' => 'Retainer', 'won_at' => '2026-11-05 10:00:00']);
    ($this->deal)('Lost', DealStatus::Lost, '500.25', ['title' => 'Audit', 'lost_at' => '2026-11-06 10:00:00']);
    ($this->deal)('Lead', DealStatus::Open, '1000', ['title' => 'Website', 'expected_close_date' => '2026-12-15']);

    $this->renderedHtml = fn (string $widget): string => Livewire::test($widget, ['pageFilters' => null])->assertSuccessful()->html();

    $this->assertTwoDecimals = function (string $html, array $amounts): void {
        foreach ($amounts as $amount) {
            expect($html)->toContain('TND'.$this->nbsp.$amount);
        }

        expect(preg_match('/TND\x{00A0}[\d,]+\.\d{3}/u', $html))->toBe(0);
    };
});

afterEach(function () {
    Filament::setTenant(null);
});

test('each widget shows its amounts with 2 decimals and none with 3', function (string $widget, array $amounts) {
    ($this->assertTwoDecimals)(($this->renderedHtml)($widget), $amounts);
})->with([
    'kpi overview' => [KpiOverviewWidget::class, ['1,234.50', '0.00']],
    'deals closed' => [DealsClosedWidget::class, ['1,234.50', '500.25']],
    'deals created' => [DealsCreatedWidget::class, ['2,734.75']],
    'sales results' => [SalesResultsWidget::class, ['1,234.50', '500.25']],
    'sales velocity' => [SalesVelocityWidget::class, ['205.75 per day', '1,234.50 average won deal']],
    'deal forecast' => [DealForecastWidget::class, ['1,000.00', '100.00', '0.00']],
    'team activity' => [TeamActivityWidget::class, ['1,234.50']],
    'lead sources' => [LeadSourcesWidget::class, ['1,234.50']],
]);

test('an EUR organization is unchanged', function () {
    $this->org->update(['currency' => Currency::Eur]);
    Filament::setTenant($this->org->refresh());

    $html = ($this->renderedHtml)(DealsClosedWidget::class);

    expect($html)->toContain('€1,234.50')
        ->and($html)->toContain('€500.25');
});

test('the 3 decimals check bites on a 3 decimal amount', function () {
    expect(preg_match('/TND\x{00A0}[\d,]+\.\d{3}/u', 'TND'.$this->nbsp.'1,234.500'))->toBe(1)
        ->and(preg_match('/TND\x{00A0}[\d,]+\.\d{3}/u', 'TND'.$this->nbsp.'1,234.50'))->toBe(0);
});
