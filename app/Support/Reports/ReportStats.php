<?php

namespace App\Support\Reports;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Helpers shared by the report stat widgets: the comparison of a figure with the previous period and the win rate stat.
 */
final class ReportStats
{
    /**
     * Adds the comparison with the previous period to a stat: same, up or down from zero, or the percentage change
     * (relative to the absolute previous value, so a negative previous period still reads right). The wording and
     * the icon follow the direction; when a lower value is the better one ($higherIsBetter false) only the colour flips.
     */
    public static function compared(Stat $stat, int|float $current, int|float $previous, string $previousText, bool $higherIsBetter = true): Stat
    {
        if ($current == $previous) {
            return $stat->description('Same as previous period')->descriptionIcon(Heroicon::Minus)->descriptionColor('gray');
        }

        if ($previous == 0) {
            $isUp = $current > 0;

            return $stat
                ->description(($isUp ? 'Up' : 'Down').' from '.$previousText)
                ->descriptionIcon($isUp ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
                ->descriptionColor($isUp === $higherIsBetter ? 'success' : 'danger');
        }

        $change = ($current - $previous) / abs($previous) * 100;
        $isUp = $change > 0;

        return $stat
            ->description(($isUp ? '+' : '-').Number::format(abs($change), maxPrecision: 1).'% from '.$previousText)
            ->descriptionIcon($isUp ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
            ->descriptionColor($isUp === $higherIsBetter ? 'success' : 'danger');
    }

    /**
     * The share of the period's closed deals that were won, compared in points with the previous period.
     *
     * @param  string  $phrase  the period wording of the description, such as "this month"
     */
    public static function winRate(string $title, string $phrase, int $won, int $lost, int $previousWon, int $previousLost): Stat
    {
        $closed = $won + $lost;

        if ($closed === 0) {
            return Stat::make('Win Rate '.$title, '—')
                ->description('No deals closed '.$phrase);
        }

        $rate = (int) round($won * 100 / $closed);
        $summary = $won.' of '.$closed.' won · ';
        $stat = Stat::make('Win Rate '.$title, $rate.'%');
        $previousClosed = $previousWon + $previousLost;

        if ($previousClosed === 0) {
            return $stat->description($summary.'No closed deals in the previous period')->descriptionColor('gray');
        }

        $previousRate = (int) round($previousWon * 100 / $previousClosed);
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
