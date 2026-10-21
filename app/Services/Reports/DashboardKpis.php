<?php

namespace App\Services\Reports;

use App\Enums\DealStatus;
use App\Models\Contact;
use App\Models\Deal;
use App\Support\Reports\ReportPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * The figures of the dashboard KPI row for one period and an optional owner, one aggregate query per table.
 */
final class DashboardKpis
{
    /**
     * Deals won (status won and won in the period), lost (status lost and lost in the period) and created in the
     * period, with the total value of the won ones.
     *
     * @return array{won_count: int, won_value: float, lost_count: int, created_count: int}
     */
    public static function dealFigures(ReportPeriod $period, ?int $ownerId): array
    {
        [$from, $to] = $period->bounds();

        $aggregate = Deal::query()
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
            ->selectRaw(
                'COUNT(CASE WHEN status = ? AND won_at BETWEEN ? AND ? THEN 1 END) AS won_count, '.
                'COALESCE(SUM(CASE WHEN status = ? AND won_at BETWEEN ? AND ? THEN value END), 0) AS won_value, '.
                'COUNT(CASE WHEN status = ? AND lost_at BETWEEN ? AND ? THEN 1 END) AS lost_count, '.
                'COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) AS created_count',
                [
                    DealStatus::Won->value, $from, $to,
                    DealStatus::Won->value, $from, $to,
                    DealStatus::Lost->value, $from, $to,
                    $from, $to,
                ],
            )
            ->first();

        return [
            'won_count' => (int) ($aggregate?->getAttribute('won_count') ?? 0),
            'won_value' => (float) ($aggregate?->getAttribute('won_value') ?? 0),
            'lost_count' => (int) ($aggregate?->getAttribute('lost_count') ?? 0),
            'created_count' => (int) ($aggregate?->getAttribute('created_count') ?? 0),
        ];
    }

    /**
     * The contacts created in the period (soft-deleted ones excluded by the model scope).
     */
    public static function newContactsCount(ReportPeriod $period, ?int $ownerId): int
    {
        return Contact::query()
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
            ->whereBetween('created_at', $period->bounds())
            ->count();
    }
}
