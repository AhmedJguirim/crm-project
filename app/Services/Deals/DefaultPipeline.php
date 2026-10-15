<?php

namespace App\Services\Deals;

use App\Enums\DealStatus;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Gives an organization its sales pipeline: "Sales pipeline" with the six stages of the deals board (Lead, Discovery,
 * Proposal Sent, Negotiating, Won, Lost), each with a type, a color, a position and a win probability. It is idempotent:
 * an organization that already has a pipeline gets its oldest one back and nothing is created.
 */
class DefaultPipeline
{
    private const NAME = 'Sales pipeline';

    /** @var array<int, array{name: string, type: DealStatus, color: string, probability: int}> */
    private const STAGES = [
        ['name' => 'Lead', 'type' => DealStatus::Open, 'color' => 'gray', 'probability' => 10],
        ['name' => 'Discovery', 'type' => DealStatus::Open, 'color' => 'info', 'probability' => 25],
        ['name' => 'Proposal Sent', 'type' => DealStatus::Open, 'color' => 'warning', 'probability' => 50],
        ['name' => 'Negotiating', 'type' => DealStatus::Open, 'color' => 'orange', 'probability' => 75],
        ['name' => 'Won', 'type' => DealStatus::Won, 'color' => 'success', 'probability' => 100],
        ['name' => 'Lost', 'type' => DealStatus::Lost, 'color' => 'gray', 'probability' => 0],
    ];

    public static function ensureFor(Organization $organization): Pipeline
    {
        $existing = Pipeline::forOrganization($organization->getKey())->oldest('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($organization): Pipeline {
            $pipeline = Pipeline::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => self::NAME,
            ]);

            foreach (self::STAGES as $index => $stage) {
                $pipeline->stages()->create([
                    ...$stage,
                    'organization_id' => $organization->getKey(),
                    'position' => $index + 1,
                ]);
            }

            return $pipeline;
        });
    }

    /**
     * The stages of the organization's default pipeline in pipeline order. It queries with an explicit organization id
     * (never through the pipeline's relation: the tenant scope would answer for the wrong organization).
     *
     * @return Collection<int, PipelineStage>
     */
    public static function stagesOf(int $organizationId): Collection
    {
        $pipeline = self::ensureFor(Organization::query()->findOrFail($organizationId));

        return PipelineStage::forOrganization($organizationId)
            ->where('pipeline_id', $pipeline->getKey())
            ->orderBy('position')
            ->get();
    }

    /**
     * The stage ids of the organization's default pipeline by name, in pipeline order, for a select.
     *
     * @return array<int, string>
     */
    public static function stageOptions(int $organizationId): array
    {
        return self::stagesOf($organizationId)->pluck('name', 'id')->all();
    }

    /**
     * A stage of the organization's default pipeline by id; another pipeline's or organization's id is not found.
     *
     * @throws ModelNotFoundException
     */
    public static function stageById(int $organizationId, mixed $stageId): PipelineStage
    {
        $pipeline = self::ensureFor(Organization::query()->findOrFail($organizationId));

        return PipelineStage::forOrganization($organizationId)
            ->where('pipeline_id', $pipeline->getKey())
            ->findOrFail($stageId);
    }

    /**
     * The first stage (lowest position) of the default pipeline with the type: Open is "Lead", Won is "Won", Lost is "Lost".
     *
     * @throws ModelNotFoundException
     */
    public static function stageOfType(int $organizationId, DealStatus $type): PipelineStage
    {
        $pipeline = self::ensureFor(Organization::query()->findOrFail($organizationId));

        return PipelineStage::forOrganization($organizationId)
            ->where('pipeline_id', $pipeline->getKey())
            ->where('type', $type)
            ->orderBy('position')
            ->firstOrFail();
    }
}
