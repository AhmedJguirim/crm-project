<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Services\Reports\DashboardKpis;
use App\Support\Reports\ReportStats;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Dashboard figure of the average time to close: the days between creation and win of the deals won in the page's
 * period (a deal won the day it was created counts as under one day), compared with the previous period, a lower
 * value being the better one, and drawn as a sparkline of the last 6 periods. The owner filter applies to the deal owner.
 */
class AverageTimeToCloseWidget extends DashboardStatsWidget
{
    private const int TREND_PERIODS = 6;

    private const int SECONDS_PER_DAY = 86400;

    protected int|array|null $columns = 1;

    protected function getHeading(): ?string
    {
        return 'Average time to close';
    }

    protected function getStats(): array
    {
        $filters = $this->filters();
        $results = DashboardKpis::salesResultsByPeriod($filters->period->trail(self::TREND_PERIODS), $filters->ownerId);
        $days = array_map(
            fn (array $figures): ?float => $figures['won_count'] === 0 ? null : $figures['won_cycle_seconds'] / $figures['won_count'] / self::SECONDS_PER_DAY,
            $results,
        );

        $current = $days[self::TREND_PERIODS - 1];
        $previous = $days[self::TREND_PERIODS - 2];
        $title = $filters->period->title();

        if ($current === null) {
            return [Stat::make('Average Time To Close '.$title, '—')->description('No deal won '.$filters->period->phrase())];
        }

        $format = fn (float $value): string => Number::format($value, maxPrecision: 1).' days';
        $stat = Stat::make('Average Time To Close '.$title, $format($current));

        return [
            ($previous === null
                ? $stat->description('No deal won in the previous period')->descriptionColor('gray')
                : ReportStats::compared($stat, $current, $previous, $format($previous), higherIsBetter: false))
                ->chart(array_map(fn (?float $value): float => round($value ?? 0, 1), $days))
                ->chartColor('primary'),
        ];
    }
}
