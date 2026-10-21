<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\Reports\DashboardKpis;
use App\Support\Reports\DashboardFilters;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The dashboard key figures (revenue won, deals won, win rate, new deals, cash collected, new contacts) of the page's period and owner
 * filters, each compared with the previous period of the same length and drawn as a sparkline of the last 6 periods
 * (the current one last). Every figure is "more is better"; cash collected (payments minus refunds of the invoice
 * ledger) can be negative.
 */
class KpiOverviewWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    private const int TREND_PERIODS = 6;

    protected ?string $pollingInterval = null;

    protected static ?int $sort = -45;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = ['@xl' => 3, '@5xl' => 6, '!@lg' => 3, '!@xl' => 6];

    protected function getHeading(): ?string
    {
        return 'Key figures';
    }

    protected function getDescription(): ?string
    {
        return 'Compared with the previous period, '.$this->dashboardFilters()->period->previous()->phrase().'.';
    }

    /**
     * The dashboard filters, validated (see DashboardFilters).
     */
    protected function dashboardFilters(): DashboardFilters
    {
        return DashboardFilters::fromPageFilters($this->pageFilters);
    }

    protected function getStats(): array
    {
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';
        $filters = $this->dashboardFilters();
        $period = $filters->period;
        $ownerId = $filters->ownerId;

        $periods = $period->trail(self::TREND_PERIODS);
        $deals = DashboardKpis::dealFiguresByPeriod($periods, $ownerId);
        $contacts = DashboardKpis::newContactsCountByPeriod($periods, $ownerId);
        $cashByPeriod = DashboardKpis::cashCollectedByPeriod($periods, $ownerId);

        $current = $deals[self::TREND_PERIODS - 1];
        $previous = $deals[self::TREND_PERIODS - 2];
        $newContacts = $contacts[self::TREND_PERIODS - 1];
        $previousNewContacts = $contacts[self::TREND_PERIODS - 2];
        $cash = $cashByPeriod[self::TREND_PERIODS - 1];
        $previousCash = $cashByPeriod[self::TREND_PERIODS - 2];

        $title = $period->title();
        $wonUrl = DealResource::getUrl('index').'?tableFilters[status][value]=won';

        return [
            $this->withTrend($this->compared(
                Stat::make('Revenue Won '.$title, Number::currency($current['won_value'], $currency)),
                $current['won_value'],
                $previous['won_value'],
                Number::currency($previous['won_value'], $currency),
            )->url($wonUrl), array_column($deals, 'won_value')),

            $this->withTrend($this->compared(
                Stat::make('Deals Won '.$title, (string) $current['won_count']),
                $current['won_count'],
                $previous['won_count'],
                (string) $previous['won_count'],
            )->url($wonUrl), array_column($deals, 'won_count')),

            $this->withTrend($this->winRate($title, $current, $previous), array_map($this->winRatePoints(...), $deals)),

            $this->withTrend($this->compared(
                Stat::make('New Deals '.$title, (string) $current['created_count']),
                $current['created_count'],
                $previous['created_count'],
                (string) $previous['created_count'],
            )->url(DealResource::getUrl('index')), array_column($deals, 'created_count')),

            $this->withTrend($this->compared(
                Stat::make('Cash Collected '.$title, Number::currency($cash, $currency)),
                $cash,
                $previousCash,
                Number::currency($previousCash, $currency),
            )->url(InvoiceResource::getUrl('index')), $cashByPeriod),

            $this->withTrend($this->compared(
                Stat::make('New Contacts '.$title, (string) $newContacts),
                $newContacts,
                $previousNewContacts,
                (string) $previousNewContacts,
            )->url(ContactResource::getUrl('index')), $contacts),
        ];
    }

    /**
     * Draws the values of the last periods (oldest first) as the stat's sparkline.
     *
     * @param  list<int|float>  $values
     */
    private function withTrend(Stat $stat, array $values): Stat
    {
        return $stat->chart(array_values($values))->chartColor('primary');
    }

    /**
     * The win rate of one period as a whole percentage for the sparkline, 0 when no deal was closed in it.
     *
     * @param  array{won_count: int, won_value: float, lost_count: int, created_count: int}  $figures
     */
    private function winRatePoints(array $figures): int
    {
        $closed = $figures['won_count'] + $figures['lost_count'];

        return $closed === 0 ? 0 : (int) round($figures['won_count'] * 100 / $closed);
    }

    /**
     * Adds the comparison with the previous period to a stat: same, up or down from zero, or the percentage change
     * (relative to the absolute previous value, so a negative previous period still reads right).
     */
    private function compared(Stat $stat, int|float $current, int|float $previous, string $previousText): Stat
    {
        if ($current == $previous) {
            return $stat->description('Same as previous period')->descriptionIcon(Heroicon::Minus)->descriptionColor('gray');
        }

        if ($previous == 0) {
            $isUp = $current > 0;

            return $stat
                ->description(($isUp ? 'Up' : 'Down').' from '.$previousText)
                ->descriptionIcon($isUp ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
                ->descriptionColor($isUp ? 'success' : 'danger');
        }

        $change = ($current - $previous) / abs($previous) * 100;
        $isUp = $change > 0;

        return $stat
            ->description(($isUp ? '+' : '-').Number::format(abs($change), maxPrecision: 1).'% from '.$previousText)
            ->descriptionIcon($isUp ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
            ->descriptionColor($isUp ? 'success' : 'danger');
    }

    /**
     * The share of the period's closed deals that were won, compared in points with the previous period.
     *
     * @param  array{won_count: int, won_value: float, lost_count: int, created_count: int}  $current
     * @param  array{won_count: int, won_value: float, lost_count: int, created_count: int}  $previous
     */
    private function winRate(string $title, array $current, array $previous): Stat
    {
        $closed = $current['won_count'] + $current['lost_count'];

        if ($closed === 0) {
            return Stat::make('Win Rate '.$title, '—')
                ->description('No deals closed '.$this->dashboardFilters()->period->phrase());
        }

        $rate = (int) round($current['won_count'] * 100 / $closed);
        $summary = $current['won_count'].' of '.$closed.' won · ';
        $stat = Stat::make('Win Rate '.$title, $rate.'%');
        $previousClosed = $previous['won_count'] + $previous['lost_count'];

        if ($previousClosed === 0) {
            return $stat->description($summary.'No closed deals in the previous period')->descriptionColor('gray');
        }

        $previousRate = (int) round($previous['won_count'] * 100 / $previousClosed);
        $points = $rate - $previousRate;

        if ($points === 0) {
            return $stat->description($summary.'Same as previous period')->descriptionIcon(Heroicon::Minus)->descriptionColor('gray');
        }

        $isUp = $points > 0;

        return $stat
            ->description($summary.($isUp ? '+' : '-').abs($points).' pts from '.$previousRate.'%')
            ->descriptionIcon($isUp ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
            ->descriptionColor($isUp ? 'success' : 'danger');
    }
}
