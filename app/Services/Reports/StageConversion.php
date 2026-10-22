<?php

namespace App\Services\Reports;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Services\Deals\DefaultPipeline;
use App\Support\Reports\ReportPeriod;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How far the deals created in a report period got through the default pipeline of the current organization. The
 * cohort is the deals created in the period (local days of the organization timezone), for one owner or everyone.
 * The furthest stage of a deal is the highest position among every stage it ever stood in (its history plus its
 * current stage, which covers deals older than the history). Lost stages are ignored, history rows of a deleted stage
 * are ignored, and a deal that only ever stood in Lost has no furthest stage: it counts in the cohort but in no row.
 * A deal counts at every open stage up to its furthest one, so a skipped stage counts as reached, and a deal that stood
 * in Won counts at every open stage. The last row counts the cohort deals that are Won now (their current status), so
 * a reopened deal is not counted there. Rows follow the current names and order of the stages; a deleted stage has none.
 */
final class StageConversion
{
    /**
     * @return array{deals_created: int, rows: array<string, array{stage: string, is_won: bool, deals: int, of_created: string, from_previous: string}>}
     */
    public static function forPeriod(ReportPeriod $period, ?int $ownerId): array
    {
        $organizationId = CurrentOrganization::find()?->getKey();

        if ($organizationId === null) {
            return ['deals_created' => 0, 'rows' => []];
        }

        [$from, $to] = $period->bounds();

        $cohort = Deal::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId));

        $totals = (clone $cohort)
            ->selectRaw('COUNT(*) AS created_count, COUNT(CASE WHEN status = ? THEN 1 END) AS won_count', [DealStatus::Won->value])
            ->toBase()
            ->first();

        $created = (int) ($totals->created_count ?? 0);

        if ($created === 0) {
            return ['deals_created' => 0, 'rows' => []];
        }

        $stages = DefaultPipeline::stagesOf($organizationId);
        $dealsByFurthestPosition = self::dealsByFurthestPosition($cohort, $organizationId, (int) $stages->first()?->pipeline_id);

        $rows = [];
        $previous = null;

        foreach ($stages->where('type', DealStatus::Open) as $stage) {
            $deals = array_sum(array_filter(
                $dealsByFurthestPosition,
                fn (int $position): bool => $position >= $stage->position,
                ARRAY_FILTER_USE_KEY,
            ));

            $rows["stage-{$stage->getKey()}"] = self::row($stage->name, false, $deals, $created, $previous);
            $previous = $deals;
        }

        $wonStage = $stages->firstWhere('type', DealStatus::Won);

        if ($wonStage !== null) {
            $rows['won'] = self::row($wonStage->name, true, (int) ($totals->won_count ?? 0), $created, $previous);
        }

        return ['deals_created' => $created, 'rows' => $rows];
    }

    /**
     * The number of cohort deals by the position of the furthest non-Lost stage they stood in.
     *
     * @param  Builder<Deal>  $cohort
     * @return array<int, int> position => deals
     */
    private static function dealsByFurthestPosition(Builder $cohort, int $organizationId, int $pipelineId): array
    {
        $stagesStoodIn = (clone $cohort)
            ->select(['id AS deal_id', 'pipeline_stage_id AS stage_id'])
            ->unionAll(
                DealStageChange::query()
                    ->whereIn('deal_id', (clone $cohort)->select('id'))
                    ->whereNotNull('to_pipeline_stage_id')
                    ->select(['deal_id', 'to_pipeline_stage_id AS stage_id'])
            );

        $furthest = DB::query()
            ->fromSub($stagesStoodIn, 'stood_in')
            ->join('pipeline_stages', 'pipeline_stages.id', '=', 'stood_in.stage_id')
            ->where('pipeline_stages.organization_id', $organizationId)
            ->where('pipeline_stages.pipeline_id', $pipelineId)
            ->where('pipeline_stages.type', '<>', DealStatus::Lost->value)
            ->groupBy('stood_in.deal_id')
            ->select('stood_in.deal_id')
            ->selectRaw('MAX(pipeline_stages.position) AS furthest_position');

        return DB::query()
            ->fromSub($furthest, 'furthest')
            ->selectRaw('furthest_position, COUNT(*) AS deals_count')
            ->groupBy('furthest_position')
            ->pluck('deals_count', 'furthest_position')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return array{stage: string, is_won: bool, deals: int, of_created: string, from_previous: string}
     */
    private static function row(string $stage, bool $isWon, int $deals, int $created, ?int $previous): array
    {
        return [
            'stage' => $stage,
            'is_won' => $isWon,
            'deals' => $deals,
            'of_created' => self::percent($deals, $created),
            'from_previous' => $previous === null ? '—' : self::percent($deals, $previous),
        ];
    }

    private static function percent(int $part, int $whole): string
    {
        return $whole === 0 ? '—' : ((int) round($part / $whole * 100)).'%';
    }
}
