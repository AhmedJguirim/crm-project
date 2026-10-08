<?php

namespace App\Services\Deals;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Models\Deal;
use Carbon\CarbonInterface;

final class DealStageMover
{
    /**
     * The stage decides the status, and the status decides the won and lost dates.
     * A deal that stays Won (or Lost) keeps its date; a deal that leaves it loses the date.
     *
     * @param  Deal|null  $deal  the deal before the change, null when it is being created
     * @return array{
     *     stage: DealStage,
     *     status: DealStatus,
     *     won_at: ?CarbonInterface,
     *     lost_at: ?CarbonInterface
     * }
     */
    public static function attributesFor(DealStage|string $stage, ?Deal $deal = null): array
    {
        $stage = $stage instanceof DealStage ? $stage : DealStage::from($stage);

        $status = match ($stage) {
            DealStage::Won => DealStatus::Won,
            DealStage::Lost => DealStatus::Lost,
            default => DealStatus::Open,
        };

        return [
            'stage' => $stage,
            'status' => $status,
            'won_at' => $status === DealStatus::Won ? ($deal?->won_at ?? now()) : null,
            'lost_at' => $status === DealStatus::Lost ? ($deal?->lost_at ?? now()) : null,
        ];
    }

    /**
     * Moves the deal to the stage and saves it through model events, so the segments are resynced.
     */
    public static function move(Deal $deal, DealStage|string $stage): Deal
    {
        $deal->fill(self::attributesFor($stage, $deal))->save();

        return $deal;
    }
}
