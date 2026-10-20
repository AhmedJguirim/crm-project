<?php

namespace App\Services\Deals;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\PipelineStage;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

final class DealStageMover
{
    /**
     * The stage decides the status (its type), and the status decides the won and lost dates.
     * A deal that stays Won (or Lost) keeps its date; a deal that leaves it loses the date.
     *
     * @param  Deal|null  $deal  the deal before the change, null when it is being created
     * @return array{
     *     pipeline_stage_id: int,
     *     status: DealStatus,
     *     won_at: ?CarbonInterface,
     *     lost_at: ?CarbonInterface
     * }
     */
    public static function attributesFor(PipelineStage $stage, ?Deal $deal = null): array
    {
        $status = $stage->type;

        return [
            'pipeline_stage_id' => $stage->getKey(),
            'status' => $status,
            'won_at' => $status === DealStatus::Won ? ($deal?->won_at ?? now()) : null,
            'lost_at' => $status === DealStatus::Lost ? ($deal?->lost_at ?? now()) : null,
        ];
    }

    /**
     * Moves the deal to the stage and saves it through model events, so the segments are resynced.
     */
    public static function move(Deal $deal, PipelineStage $stage): Deal
    {
        $deal->fill(self::attributesFor($stage, $deal))->save();

        return $deal;
    }

    /**
     * Writes the history row of a deal that was created (no from stage) or moved to another stage. The only code that
     * creates `DealStageChange` rows; the deal observer calls it, so every save that changes the stage is recorded.
     */
    public static function recordStageChange(Deal $deal, ?int $fromStageId, CarbonInterface $changedAt): DealStageChange
    {
        return DealStageChange::create([
            'organization_id' => $deal->organization_id,
            'deal_id' => $deal->getKey(),
            'from_pipeline_stage_id' => $fromStageId,
            'to_pipeline_stage_id' => (int) $deal->pipeline_stage_id,
            'changed_by' => Auth::id(),
            'changed_at' => $changedAt,
        ]);
    }
}
