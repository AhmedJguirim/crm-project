<?php

namespace App\Services\Deals;

use App\Enums\DealStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Adds and reorders the stages of a pipeline: the open stages in the wanted order, then the closed stages (Won, Lost) in
 * their current order, with positions rewritten 1..N without gaps. Won and Lost always stay last, whatever order is asked.
 */
final class PipelineStageOrder
{
    /**
     * A new open stage, appended after the existing open stages (just before Won). Only the name, color and probability
     * are read from the attributes; the type, position, pipeline and organization are never taken from them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function addOpenStage(Pipeline $pipeline, array $attributes): PipelineStage
    {
        return DB::transaction(function () use ($pipeline, $attributes): PipelineStage {
            $stages = self::lockedStagesOf($pipeline);

            $stage = PipelineStage::query()->create([
                ...Arr::only($attributes, ['name', 'color', 'probability']),
                'organization_id' => $pipeline->organization_id,
                'pipeline_id' => $pipeline->getKey(),
                'type' => DealStatus::Open,
                'position' => 0,
            ]);

            $openIds = $stages->where('type', DealStatus::Open)->modelKeys();
            $openIds[] = $stage->getKey();

            self::renumber($stages->push($stage), $openIds);

            return $stage->refresh();
        });
    }

    /**
     * Puts the open stages in the given order. Keys that are not the id of an open stage of this pipeline are ignored,
     * open stages missing from the order follow in their current order, and Won and Lost stay last.
     *
     * @param  array<int, int|string>  $order  the record keys sent by the browser
     * @return bool whether the order put a Won or Lost stage before an open stage of the pipeline
     */
    public static function reorderOpenStages(Pipeline $pipeline, array $order): bool
    {
        return DB::transaction(function () use ($pipeline, $order): bool {
            $stages = self::lockedStagesOf($pipeline);
            $openIds = $stages->where('type', DealStatus::Open)->modelKeys();
            $closedIds = $stages->where('type', '!=', DealStatus::Open)->modelKeys();

            $wanted = [];
            $closedSeen = false;
            $openBeforeClosed = 0;

            foreach ($order as $key) {
                $id = filter_var($key, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

                if ($id === false || in_array($id, $wanted, true)) {
                    continue;
                }

                if (in_array($id, $closedIds, true)) {
                    $closedSeen = true;
                    $wanted[] = $id;

                    continue;
                }

                if (! in_array($id, $openIds, true)) {
                    continue;
                }

                $wanted[] = $id;
                $openBeforeClosed += $closedSeen ? 0 : 1;
            }

            $wantedOpen = array_values(array_intersect($wanted, $openIds));
            $openInOrder = [...$wantedOpen, ...array_values(array_diff($openIds, $wantedOpen))];

            self::renumber($stages, $openInOrder);

            return $closedSeen && $openBeforeClosed < count($openIds);
        });
    }

    /**
     * Locks the pipeline row, then loads all its stages by position (ties broken by id). It queries with explicit ids,
     * never through the pipeline's relation: the tenant scope would answer for the wrong organization.
     *
     * @return Collection<int, PipelineStage>
     */
    private static function lockedStagesOf(Pipeline $pipeline): Collection
    {
        Pipeline::forOrganization($pipeline->organization_id)->whereKey($pipeline->getKey())->lockForUpdate()->firstOrFail();

        return PipelineStage::forOrganization($pipeline->organization_id)
            ->where('pipeline_id', $pipeline->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    /**
     * Writes positions 1..N: the open stages in the given order, then the other stages in their current order.
     *
     * @param  Collection<int, PipelineStage>  $stages
     * @param  array<int, int>  $openIdsInOrder
     */
    private static function renumber(Collection $stages, array $openIdsInOrder): void
    {
        $byId = $stages->keyBy(fn (PipelineStage $stage): int => $stage->getKey());

        $ordered = collect($openIdsInOrder)
            ->map(fn (int $id): PipelineStage => $byId->get($id))
            ->concat($stages->filter(fn (PipelineStage $stage): bool => $stage->type !== DealStatus::Open));

        foreach ($ordered->values() as $index => $stage) {
            $stage->position = $index + 1;
            $stage->save();
        }
    }
}
