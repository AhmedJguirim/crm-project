<?php

namespace App\Services\Reports;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Carbon\CarbonImmutable;

/**
 * The unpaid invoices of the current organization grouped by how late they are.
 */
final class Receivables
{
    public const BUCKETS = ['not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90'];

    /**
     * Count and unpaid balance (amount minus amount paid) of the outstanding invoices (sent, partial, overdue) by age.
     * Days late is the given local today minus the due date; an invoice with the Overdue status that is not yet past
     * its due date counts as 1 to 30 days late. Every bucket is returned, empty ones with zeros, in the order of
     * BUCKETS.
     *
     * @param  string  $todayDate  the organization's local today as `Y-m-d`
     * @return array<'not_due'|'days_1_30'|'days_31_60'|'days_61_90'|'over_90', array{count: int, balance: float}>
     */
    public static function aging(string $todayDate): array
    {
        $today = CarbonImmutable::createFromFormat('!Y-m-d', $todayDate);

        $rows = Invoice::query()
            ->outstanding()
            ->selectRaw(
                "CASE WHEN due_at < ? THEN 'over_90' ".
                "WHEN due_at < ? THEN 'days_61_90' ".
                "WHEN due_at < ? THEN 'days_31_60' ".
                "WHEN due_at < ? OR status = ? THEN 'days_1_30' ".
                "ELSE 'not_due' END AS bucket, ".
                'COUNT(*) AS invoices_count, COALESCE(SUM(amount - amount_paid), 0) AS balance',
                [
                    $today->subDays(90)->toDateString(),
                    $today->subDays(60)->toDateString(),
                    $today->subDays(30)->toDateString(),
                    $todayDate,
                    InvoiceStatus::Overdue->value,
                ],
            )
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $aging = [];

        foreach (self::BUCKETS as $bucket) {
            $row = $rows->get($bucket);

            $aging[$bucket] = [
                'count' => (int) ($row?->getAttribute('invoices_count') ?? 0),
                'balance' => (float) ($row?->getAttribute('balance') ?? 0),
            ];
        }

        return $aging;
    }
}
