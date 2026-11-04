<?php

namespace App\Services\Reports;

use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use stdClass;

/**
 * The generic aggregates of the Dashboard charts: rows counted per value of a column, and rows created per local
 * day or month of a report period. Both take a tenant-scoped query the caller already narrowed (owner, period, status),
 * so every record rule of the caller (soft deletes, organization) applies unchanged.
 */
final class DashboardBreakdowns
{
    /**
     * A period longer than this many days is drawn per month, a shorter one per day.
     */
    private const int MAX_DAILY_POINTS = 92;

    /**
     * The rows of the query counted per value of one of its own columns (an unqualified column name from code, not
     * user input). A null value is counted under the empty string key.
     *
     * @param  Builder<*>  $query
     * @return array<string, int>
     */
    public static function countsBy(Builder $query, string $column): array
    {
        $counts = [];

        /** @var stdClass $row */
        foreach ($query->groupBy($column)->select($column)->selectRaw('COUNT(*) AS rows_count')->toBase()->get() as $row) {
            $counts[(string) $row->{$column}] = (int) $row->rows_count;
        }

        return $counts;
    }

    /**
     * The rows of the query created in the period, counted per local day (or per local month when the period is long),
     * with a zero for every day or month without a row. The keys are the first local day of each bucket as `Y-m-d`.
     *
     * @param  Builder<*>  $query
     * @return array{unit: 'day'|'month', points: array<string, int>}
     */
    public static function createdSeries(Builder $query, ReportPeriod $period): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $period->startDate, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $period->endDate, 'UTC');
        $unit = $start->diffInDays($end) > self::MAX_DAILY_POINTS ? 'month' : 'day';

        $found = [];

        /** @var stdClass $row */
        foreach ($query
            ->whereBetween('created_at', $period->bounds())
            ->groupBy('bucket')
            ->selectRaw("CAST(date_trunc(?, (created_at AT TIME ZONE 'UTC') AT TIME ZONE ?) AS date) AS bucket, COUNT(*) AS rows_count", [$unit, $period->timezone])
            ->toBase()
            ->get() as $row) {
            $found[(string) $row->bucket] = (int) $row->rows_count;
        }

        $points = [];

        for ($cursor = $unit === 'month' ? $start->startOfMonth() : $start; $cursor <= $end; $cursor = $unit === 'month' ? $cursor->addMonth() : $cursor->addDay()) {
            $points[$cursor->toDateString()] = $found[$cursor->toDateString()] ?? 0;
        }

        return ['unit' => $unit, 'points' => $points];
    }
}
