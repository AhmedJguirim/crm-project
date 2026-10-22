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
 * How long deals stay in each open stage of the default pipeline of the current organization. Every stage history row
 * starts a stay in its stage and the stay ends at the next row of the same deal (ordered by time, then id); a row
 * without a next one is the current stay. The average covers the closed stays in an open stage that ended inside the
 * report period (local days of the organization timezone), of deals of any status; a history row of a deleted stage
 * starts no counted stay but still ends the stay before it. The owner filter is the current owner of the deal, for
 * the stays and for the open deals alike. "In stage now" counts the open deals in the stage whatever the period, and
 * a deal is over the average when its current stay (from `Deal::stageEnteredAt()`, no `created_at` fallback) is
 * strictly longer than the average of its stage; a deal without usable history is never over.
 */
final class TimeInStage
{
    private const SECONDS_PER_DAY = 86400;

    /**
     * @return array<string, array{stage: string, average_days: string, stays: int, in_stage_now: int, over_average: string}>
     */
    public static function forPeriod(ReportPeriod $period, ?int $ownerId): array
    {
        $organizationId = CurrentOrganization::find()?->getKey();

        if ($organizationId === null) {
            return [];
        }

        $openStages = DefaultPipeline::stagesOf($organizationId)->where('type', DealStatus::Open);

        if ($openStages->isEmpty()) {
            return [];
        }

        $deals = Deal::query()->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId));

        $stays = DealStageChange::query()
            ->whereIn('deal_id', (clone $deals)->select('id'))
            ->select(['to_pipeline_stage_id', 'changed_at'])
            ->selectRaw('LEAD(changed_at) OVER (PARTITION BY deal_id ORDER BY changed_at, id) AS left_at');

        $closed = DB::query()
            ->fromSub($stays, 'stays')
            ->whereNotNull('stays.to_pipeline_stage_id')
            ->whereBetween('stays.left_at', $period->bounds())
            ->groupBy('stays.to_pipeline_stage_id')
            ->select('stays.to_pipeline_stage_id AS stage_id')
            ->selectRaw('COUNT(*) AS stays_count, AVG(EXTRACT(EPOCH FROM (stays.left_at - stays.changed_at))) AS average_seconds')
            ->get()
            ->keyBy('stage_id');

        $openDeals = (clone $deals)->open()
            ->whereIn('pipeline_stage_id', $openStages->modelKeys())
            ->with('latestStageChange')
            ->get(['id', 'pipeline_stage_id'])
            ->groupBy('pipeline_stage_id');

        $now = now()->getTimestamp();
        $rows = [];

        foreach ($openStages as $stage) {
            $stayStats = $closed->get($stage->getKey());
            $averageSeconds = $stayStats === null ? null : (float) $stayStats->average_seconds;
            $inStage = $openDeals->get($stage->getKey(), collect());

            $rows["stage-{$stage->getKey()}"] = [
                'stage' => $stage->name,
                'average_days' => $averageSeconds === null ? '—' : number_format($averageSeconds / self::SECONDS_PER_DAY, 1),
                'stays' => (int) ($stayStats->stays_count ?? 0),
                'in_stage_now' => $inStage->count(),
                'over_average' => $averageSeconds === null ? '—' : (string) $inStage->filter(
                    fn (Deal $deal): bool => $deal->stageEnteredAt() !== null && $now - $deal->stageEnteredAt()->getTimestamp() > $averageSeconds,
                )->count(),
            ];
        }

        return $rows;
    }
}
