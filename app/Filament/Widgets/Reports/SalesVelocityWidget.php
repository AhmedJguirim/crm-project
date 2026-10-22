<?php

namespace App\Filament\Widgets\Reports;

use App\Services\Reports\SalesVelocity;
use App\Support\Reports\DashboardFilters;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Reports page figure of the sales velocity: what the open deals are expected to bring per day, with the inputs written
 * under it (open deals now, average won deal, win rate and average sales cycle of the deals won or lost in the page's
 * period and owner filters). There is no comparison with the previous period because the open deals of a past period
 * are not stored. It is not discovered onto the dashboard: only the Reports page lists it.
 */
class SalesVelocityWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return 'Sales velocity';
    }

    protected function getDescription(): ?string
    {
        $period = DashboardFilters::fromPageFilters($this->pageFilters)->period;

        return 'What the open deals are expected to bring per day: open deals now × average won deal × win rate ÷ average sales cycle, from the deals won or lost '.$period->phrase().'.';
    }

    protected function getStats(): array
    {
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';
        $filters = DashboardFilters::fromPageFilters($this->pageFilters);
        $period = $filters->period;

        $label = 'Sales Velocity '.$period->title();
        $velocity = SalesVelocity::forPeriod($period, $filters->ownerId);

        if ($velocity === null) {
            return [
                Stat::make($label, '—')
                    ->description('No deals won '.$period->phrase().', so there is no velocity to show')
                    ->descriptionColor('gray'),
            ];
        }

        $openDeals = $velocity['open_deals'];
        $cycleDays = (int) round($velocity['cycle_days']);

        return [
            Stat::make($label, Number::currency($velocity['per_day'], $currency).' per day')
                ->description(
                    $openDeals.' open '.Str::plural('deal', $openDeals).
                    ' × '.Number::currency($velocity['average_won_value'], $currency).' average won deal'.
                    ' × '.(int) round($velocity['win_rate'] * 100).'% win rate ('.$velocity['won_count'].' of '.$velocity['closed_count'].')'.
                    ' ÷ '.$cycleDays.' '.Str::plural('day', $cycleDays).' average cycle'
                )
                ->descriptionColor('gray'),
        ];
    }
}
