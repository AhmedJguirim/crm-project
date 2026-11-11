<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Services\Reports\DashboardKpis;
use App\Support\Reports\ContactListLink;
use App\Support\Reports\ReportStats;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Dashboard figure of the contacts created in the page's period (the rule of the "New Contacts" KPI), compared with
 * the previous period and drawn as a sparkline of the last 6 periods.
 */
class NewContactsWidget extends DashboardStatsWidget
{
    private const int TREND_PERIODS = 6;

    protected int|array|null $columns = 1;

    protected function getStats(): array
    {
        $filters = $this->filters();
        $counts = DashboardKpis::newContactsCountByPeriod($filters->period->trail(self::TREND_PERIODS), $filters->ownerId);
        $current = $counts[self::TREND_PERIODS - 1];
        $previous = $counts[self::TREND_PERIODS - 2];

        return [
            ReportStats::compared(
                Stat::make('New Contacts Created '.$filters->period->title(), (string) $current),
                $current,
                $previous,
                (string) $previous,
            )->url(ContactListLink::created($filters))->chart($counts)->chartColor('primary'),
        ];
    }
}
