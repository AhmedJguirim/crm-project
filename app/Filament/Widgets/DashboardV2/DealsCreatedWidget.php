<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Models\Deal;
use App\Services\Reports\DashboardKpis;
use App\Support\Reports\DealListLink;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\ReportStats;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Dashboard figures of the deals created in the page's period: how many and their total value, each compared with
 * the previous period, the count drawn as a sparkline of the last 6 periods. The owner filter applies to the deal owner.
 */
class DealsCreatedWidget extends DashboardStatsWidget
{
    private const int TREND_PERIODS = 6;

    protected int|array|null $columns = ['@xl' => 2, '!@lg' => 2];

    protected function getHeading(): ?string
    {
        return 'Deals created';
    }

    protected function getStats(): array
    {
        $filters = $this->filters();
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';
        $periods = $filters->period->trail(self::TREND_PERIODS);
        $counts = array_column(DashboardKpis::dealFiguresByPeriod($periods, $filters->ownerId), 'created_count');
        $valueOf = fn (ReportPeriod $period): float => (float) Deal::query()
            ->whereBetween('created_at', $period->bounds())
            ->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId))
            ->sum('value');

        $value = $valueOf($filters->period);
        $previousValue = $valueOf($filters->period->previous());

        return [
            ReportStats::compared(
                Stat::make('Deals Created '.$filters->period->title(), (string) $counts[self::TREND_PERIODS - 1]),
                $counts[self::TREND_PERIODS - 1],
                $counts[self::TREND_PERIODS - 2],
                (string) $counts[self::TREND_PERIODS - 2],
            )->url(DealListLink::created($filters))->chart($counts)->chartColor('primary'),

            ReportStats::compared(
                Stat::make('Value Created '.$filters->period->title(), Number::currency($value, $currency)),
                $value,
                $previousValue,
                Number::currency($previousValue, $currency),
            )->url(DealListLink::created($filters)),
        ];
    }
}
