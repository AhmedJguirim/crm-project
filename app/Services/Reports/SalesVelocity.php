<?php

namespace App\Services\Reports;

use App\Models\Deal;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * The sales velocity of the current organization: the value the open pipeline is expected to bring per day, as open
 * deals now x average won deal x win rate / average sales cycle in days. The average won deal, the win rate and the
 * cycle come from the deals won or lost in the report period (the figures of `DashboardKpis::salesResultsByPeriod()`,
 * current state only), the open deals are every deal with the status Open now, whatever the period and the pipeline.
 * The owner filter is the current owner of the deal on both sides. The result is computed from the unrounded inputs; a
 * cycle shorter than one day counts as one day. Without a won deal in the period there is no velocity (null).
 */
final class SalesVelocity
{
    private const SECONDS_PER_DAY = 86400;

    /**
     * @return array{open_deals: int, won_count: int, closed_count: int, average_won_value: float, win_rate: float, cycle_days: float, per_day: float}|null
     */
    public static function forPeriod(ReportPeriod $period, ?int $ownerId): ?array
    {
        $results = DashboardKpis::salesResultsByPeriod([$period], $ownerId)[0];
        $wonCount = $results['won_count'];

        if ($wonCount === 0) {
            return null;
        }

        $openDeals = Deal::query()
            ->open()
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
            ->count();

        $closedCount = $wonCount + $results['lost_count'];
        $averageWonValue = $results['won_value'] / $wonCount;
        $winRate = $wonCount / $closedCount;
        $cycleDays = max($results['won_cycle_seconds'] / $wonCount / self::SECONDS_PER_DAY, 1.0);

        return [
            'open_deals' => $openDeals,
            'won_count' => $wonCount,
            'closed_count' => $closedCount,
            'average_won_value' => $averageWonValue,
            'win_rate' => $winRate,
            'cycle_days' => $cycleDays,
            'per_day' => $openDeals * $averageWonValue * $winRate / $cycleDays,
        ];
    }
}
