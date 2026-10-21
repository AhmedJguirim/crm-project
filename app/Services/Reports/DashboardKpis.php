<?php

namespace App\Services\Reports;

use App\Enums\DealStatus;
use App\Enums\InvoicePaymentKind;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Support\Reports\ReportPeriod;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The figures of the dashboard KPI row for a list of periods (oldest first) and an optional owner, one UNION ALL query
 * per table for all the periods.
 */
final class DashboardKpis
{
    /**
     * Per period: deals won (status won and won in the period), lost (status lost and lost in the period) and created
     * in the period, with the total value of the won ones. Periods without a deal are zeros.
     *
     * @param  list<ReportPeriod>  $periods
     * @return list<array{won_count: int, won_value: float, lost_count: int, created_count: int}>
     */
    public static function dealFiguresByPeriod(array $periods, ?int $ownerId): array
    {
        $rows = self::rowsByPeriod($periods, function (ReportPeriod $period, int $index) use ($ownerId): Builder {
            [$from, $to] = $period->bounds();

            return Deal::query()
                ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
                ->selectRaw(
                    'COUNT(CASE WHEN status = ? AND won_at BETWEEN ? AND ? THEN 1 END) AS won_count, '.
                    'COALESCE(SUM(CASE WHEN status = ? AND won_at BETWEEN ? AND ? THEN value END), 0) AS won_value, '.
                    'COUNT(CASE WHEN status = ? AND lost_at BETWEEN ? AND ? THEN 1 END) AS lost_count, '.
                    'COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) AS created_count, '.
                    'CAST(? AS integer) AS period_index',
                    [
                        DealStatus::Won->value, $from, $to,
                        DealStatus::Won->value, $from, $to,
                        DealStatus::Lost->value, $from, $to,
                        $from, $to,
                        $index,
                    ],
                );
        });

        return array_map(fn (int $index): array => [
            'won_count' => (int) ($rows[$index]?->getAttribute('won_count') ?? 0),
            'won_value' => (float) ($rows[$index]?->getAttribute('won_value') ?? 0),
            'lost_count' => (int) ($rows[$index]?->getAttribute('lost_count') ?? 0),
            'created_count' => (int) ($rows[$index]?->getAttribute('created_count') ?? 0),
        ], array_keys($periods));
    }

    /**
     * Per period, the figures of the sales results: deals won (status won and won in the period) with their total value
     * and summed sales cycle in seconds (won_at minus created_at per deal, never below 0), and deals lost (status lost
     * and lost in the period) with their total value. A null value adds 0. Periods without a deal are zeros.
     *
     * @param  list<ReportPeriod>  $periods
     * @return list<array{won_count: int, won_value: float, won_cycle_seconds: float, lost_count: int, lost_value: float}>
     */
    public static function salesResultsByPeriod(array $periods, ?int $ownerId): array
    {
        $rows = self::rowsByPeriod($periods, function (ReportPeriod $period, int $index) use ($ownerId): Builder {
            [$from, $to] = $period->bounds();

            return Deal::query()
                ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
                ->selectRaw(
                    'COUNT(CASE WHEN status = ? AND won_at BETWEEN ? AND ? THEN 1 END) AS won_count, '.
                    'COALESCE(SUM(CASE WHEN status = ? AND won_at BETWEEN ? AND ? THEN value END), 0) AS won_value, '.
                    'COALESCE(SUM(CASE WHEN status = ? AND won_at BETWEEN ? AND ? THEN GREATEST(EXTRACT(EPOCH FROM (won_at - created_at)), 0) END), 0) AS won_cycle_seconds, '.
                    'COUNT(CASE WHEN status = ? AND lost_at BETWEEN ? AND ? THEN 1 END) AS lost_count, '.
                    'COALESCE(SUM(CASE WHEN status = ? AND lost_at BETWEEN ? AND ? THEN value END), 0) AS lost_value, '.
                    'CAST(? AS integer) AS period_index',
                    [
                        DealStatus::Won->value, $from, $to,
                        DealStatus::Won->value, $from, $to,
                        DealStatus::Won->value, $from, $to,
                        DealStatus::Lost->value, $from, $to,
                        DealStatus::Lost->value, $from, $to,
                        $index,
                    ],
                );
        });

        return array_map(fn (int $index): array => [
            'won_count' => (int) ($rows[$index]?->getAttribute('won_count') ?? 0),
            'won_value' => (float) ($rows[$index]?->getAttribute('won_value') ?? 0),
            'won_cycle_seconds' => (float) ($rows[$index]?->getAttribute('won_cycle_seconds') ?? 0),
            'lost_count' => (int) ($rows[$index]?->getAttribute('lost_count') ?? 0),
            'lost_value' => (float) ($rows[$index]?->getAttribute('lost_value') ?? 0),
        ], array_keys($periods));
    }

    /**
     * Per period, the contacts created in it (soft-deleted ones excluded by the model scope).
     *
     * @param  list<ReportPeriod>  $periods
     * @return list<int>
     */
    public static function newContactsCountByPeriod(array $periods, ?int $ownerId): array
    {
        $rows = self::rowsByPeriod($periods, fn (ReportPeriod $period, int $index): Builder => Contact::query()
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
            ->whereBetween('created_at', $period->bounds())
            ->selectRaw('COUNT(*) AS contacts_count, CAST(? AS integer) AS period_index', [$index]));

        return array_map(
            fn (int $index): int => (int) ($rows[$index]?->getAttribute('contacts_count') ?? 0),
            array_keys($periods),
        );
    }

    /**
     * Per period, payments minus refunds of the invoice ledger dated in it, optionally for the invoices one user created.
     *
     * @param  list<ReportPeriod>  $periods
     * @return list<float>
     */
    public static function cashCollectedByPeriod(array $periods, ?int $ownerId): array
    {
        $rows = self::rowsByPeriod($periods, fn (ReportPeriod $period, int $index): Builder => InvoicePayment::query()
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->whereIn(
                'invoice_id',
                Invoice::query()->select('id')->where('created_by', $ownerId),
            ))
            ->whereBetween('paid_on', $period->dates())
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN kind = ? THEN amount ELSE -amount END), 0) AS cash_collected, '.
                'CAST(? AS integer) AS period_index',
                [InvoicePaymentKind::Payment->value, $index],
            ));

        return array_map(
            fn (int $index): float => (float) ($rows[$index]?->getAttribute('cash_collected') ?? 0),
            array_keys($periods),
        );
    }

    /**
     * Runs one UNION ALL query made of one part per period and returns its rows keyed by the period's index.
     *
     * @param  list<ReportPeriod>  $periods
     * @param  Closure(ReportPeriod, int): Builder  $queryForPeriod  an aggregate query that selects `period_index`
     * @return array<int, Model>
     */
    private static function rowsByPeriod(array $periods, Closure $queryForPeriod): array
    {
        $union = null;

        foreach ($periods as $index => $period) {
            $part = $queryForPeriod($period, $index);

            if ($union === null) {
                $union = $part;

                continue;
            }

            $union->unionAll($part);
        }

        if ($union === null) {
            return [];
        }

        return $union->get()->keyBy(fn (Model $row): int => (int) $row->getAttribute('period_index'))->all();
    }
}
