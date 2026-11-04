<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\DealStatus;
use App\Filament\Resources\Deals\DealResource;
use App\Services\Reports\DashboardKpis;
use App\Support\Reports\ReportStats;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Dashboard figures of the deals closed in the page's period: won and lost, with their value, each compared with the
 * previous period (the figures of the sales results, current state only). A lower lost figure is the better one. The
 * owner filter applies to the deal owner.
 */
class DealsClosedWidget extends DashboardStatsWidget
{
    protected int|array|null $columns = ['@xl' => 4, '!@lg' => 4];

    protected function getHeading(): ?string
    {
        return 'Deals closed';
    }

    protected function getStats(): array
    {
        $filters = $this->filters();
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';
        $title = $filters->period->title();
        [$previous, $current] = DashboardKpis::salesResultsByPeriod($filters->period->trail(2), $filters->ownerId);
        $wonUrl = DealResource::getUrl('index').'?filters[status][value]='.DealStatus::Won->value;
        $lostUrl = DealResource::getUrl('index').'?filters[status][value]='.DealStatus::Lost->value;

        return [
            ReportStats::compared(Stat::make('Deals Won '.$title, (string) $current['won_count']), $current['won_count'], $previous['won_count'], (string) $previous['won_count'])->url($wonUrl),
            ReportStats::compared(Stat::make('Won Value '.$title, Number::currency($current['won_value'], $currency)), $current['won_value'], $previous['won_value'], Number::currency($previous['won_value'], $currency))->url($wonUrl),
            ReportStats::compared(Stat::make('Deals Lost '.$title, (string) $current['lost_count']), $current['lost_count'], $previous['lost_count'], (string) $previous['lost_count'], higherIsBetter: false)->url($lostUrl),
            ReportStats::compared(Stat::make('Lost Value '.$title, Number::currency($current['lost_value'], $currency)), $current['lost_value'], $previous['lost_value'], Number::currency($previous['lost_value'], $currency), higherIsBetter: false)->url($lostUrl),
        ];
    }
}
