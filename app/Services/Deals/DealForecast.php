<?php

namespace App\Services\Deals;

use App\Models\Deal;
use App\Models\PipelineStage;
use Carbon\CarbonImmutable;

/**
 * The forecast of the organization's open deals by expected close month: for a window of months starting with the
 * month of the given day, the deal count, the total value and the weighted value (value x the stage's current win
 * probability). Deals closing before the window, after it or without a date get their own rows, shown only when they
 * have deals. Won and Lost deals are never counted, and the tenant scope limits everything to the current organization.
 */
final class DealForecast
{
    /**
     * @return array<string, array{label: string, deals_count: int, total: float, weighted: float}> keyed `earlier`, `Y-m` per month, `later`, `none`, `total`
     */
    public static function byCloseMonth(string $todayDate, int $months = 6): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m-d', $todayDate)->startOfMonth();

        $rows = [
            'earlier' => self::emptyRow('Earlier'),
        ];

        $monthKeys = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $first->addMonthsNoOverflow($i);
            $monthKeys[] = $month->format('Y-m');
            $rows[$month->format('Y-m')] = self::emptyRow($month->format('M Y'));
        }

        $rows['later'] = self::emptyRow('Later');
        $rows['none'] = self::emptyRow('No close date');
        $rows['total'] = self::emptyRow('Total');

        $stages = PipelineStage::query()->get()->keyBy('id');

        $groups = Deal::query()
            ->open()
            ->select(['expected_close_date', 'pipeline_stage_id'])
            ->selectRaw('COUNT(*) as deals_count, COALESCE(SUM(value), 0) as total_value')
            ->groupBy('expected_close_date', 'pipeline_stage_id')
            ->toBase()
            ->get();

        foreach ($groups as $group) {
            $key = self::bucketOf($group->expected_close_date, $monthKeys);
            $total = (float) $group->total_value;
            $weighted = (float) ($stages->get($group->pipeline_stage_id)?->weightedValue($total) ?? 0);

            foreach ([$key, 'total'] as $target) {
                $rows[$target]['deals_count'] += (int) $group->deals_count;
                $rows[$target]['total'] += $total;
                $rows[$target]['weighted'] += $weighted;
            }
        }

        foreach (['earlier', 'later', 'none'] as $optional) {
            if ($rows[$optional]['deals_count'] === 0) {
                unset($rows[$optional]);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $monthKeys  the `Y-m` keys of the window, in order
     */
    private static function bucketOf(?string $date, array $monthKeys): string
    {
        if ($date === null) {
            return 'none';
        }

        $month = substr($date, 0, 7);

        if ($month < $monthKeys[0]) {
            return 'earlier';
        }

        if ($month > end($monthKeys)) {
            return 'later';
        }

        return $month;
    }

    /**
     * @return array{label: string, deals_count: int, total: float, weighted: float}
     */
    private static function emptyRow(string $label): array
    {
        return ['label' => $label, 'deals_count' => 0, 'total' => 0.0, 'weighted' => 0.0];
    }
}
