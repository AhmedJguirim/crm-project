<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Resources\Deals\DealResource;
use App\Services\Reports\DashboardKpis;
use App\Support\Reports\DashboardFilters;
use App\Support\Reports\ReportStats;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Reports page figures of the deals won and lost in the page's period and owner filters, each compared with the previous
 * period: deals won, revenue won, average won deal, average sales cycle, deals lost, lost value and win rate. Deals are
 * counted by their current state only (a deal reopened since is in no figure). It is not discovered onto the dashboard:
 * only the Reports page lists it.
 */
class SalesResultsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return 'Sales results';
    }

    protected function getDescription(): ?string
    {
        $period = DashboardFilters::fromPageFilters($this->pageFilters)->period;

        return 'Deals won or lost '.$period->phrase().', compared with the previous period, '.$period->previous()->phrase().'. Deals reopened since are not counted.';
    }

    protected function getStats(): array
    {
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';
        $filters = DashboardFilters::fromPageFilters($this->pageFilters);
        $period = $filters->period;

        [$previous, $current] = DashboardKpis::salesResultsByPeriod($period->trail(2), $filters->ownerId);

        $title = $period->title();
        $wonUrl = DealResource::getUrl('index').'?tableFilters[status][value]=won';
        $lostUrl = DealResource::getUrl('index').'?tableFilters[status][value]=lost';

        $average = $current['won_count'] > 0 ? $current['won_value'] / $current['won_count'] : null;
        $previousAverage = $previous['won_count'] > 0 ? $previous['won_value'] / $previous['won_count'] : null;
        $cycleDays = $this->cycleDays($current);
        $previousCycleDays = $this->cycleDays($previous);

        return [
            ReportStats::compared(
                Stat::make('Deals Won '.$title, (string) $current['won_count']),
                $current['won_count'],
                $previous['won_count'],
                (string) $previous['won_count'],
            )->url($wonUrl),

            ReportStats::compared(
                Stat::make('Revenue Won '.$title, Number::currency($current['won_value'], $currency)),
                $current['won_value'],
                $previous['won_value'],
                Number::currency($previous['won_value'], $currency),
            )->url($wonUrl),

            $this->average(
                Stat::make('Average Won Deal '.$title, $average === null ? '—' : Number::currency($average, $currency)),
                $average,
                $previousAverage,
                $previousAverage === null ? '' : Number::currency($previousAverage, $currency),
                false,
                $period->phrase(),
            ),

            $this->average(
                Stat::make('Average Sales Cycle '.$title, $cycleDays === null ? '—' : $this->days($cycleDays)),
                $cycleDays,
                $previousCycleDays,
                $previousCycleDays === null ? '' : $this->days($previousCycleDays),
                true,
                $period->phrase(),
            ),

            ReportStats::compared(
                Stat::make('Deals Lost '.$title, (string) $current['lost_count']),
                $current['lost_count'],
                $previous['lost_count'],
                (string) $previous['lost_count'],
                higherIsBetter: false,
            )->url($lostUrl),

            ReportStats::compared(
                Stat::make('Lost Value '.$title, Number::currency($current['lost_value'], $currency)),
                $current['lost_value'],
                $previous['lost_value'],
                Number::currency($previous['lost_value'], $currency),
                higherIsBetter: false,
            )->url($lostUrl),

            ReportStats::winRate($title, $period->phrase(), $current['won_count'], $current['lost_count'], $previous['won_count'], $previous['lost_count']),
        ];
    }

    /**
     * The comparison of an average figure: nothing to compare without won deals in the period, a grey note without won
     * deals in the previous one.
     */
    private function average(Stat $stat, int|float|null $current, int|float|null $previous, string $previousText, bool $lowerIsBetter, string $phrase): Stat
    {
        if ($current === null) {
            return $stat->description('No deals won '.$phrase);
        }

        if ($previous === null) {
            return $stat->description('No won deals in the previous period')->descriptionColor('gray');
        }

        return ReportStats::compared($stat, $current, $previous, $previousText, higherIsBetter: ! $lowerIsBetter);
    }

    /**
     * The average sales cycle of a period's won deals in whole days, null without won deals.
     *
     * @param  array{won_count: int, won_value: float, won_cycle_seconds: float, lost_count: int, lost_value: float}  $figures
     */
    private function cycleDays(array $figures): ?int
    {
        if ($figures['won_count'] === 0) {
            return null;
        }

        return (int) round($figures['won_cycle_seconds'] / $figures['won_count'] / 86400);
    }

    private function days(int $days): string
    {
        return $days.' '.Str::plural('day', $days);
    }
}
