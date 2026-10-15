<?php

namespace App\Services\Deals;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
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
     * The stage of the organization's default pipeline at the position of the enum case (Lead 1 ... Lost 6), while the
     * `stage` column is still the source of truth. It queries with an explicit organization id so that it works without
     * a tenant and under another tenant.
     */
    public static function stageFor(int $organizationId, DealStage $stage): PipelineStage
    {
        $pipeline = self::ensureFor(Organization::query()->findOrFail($organizationId));

        return PipelineStage::forOrganization($organizationId)
            ->where('pipeline_id', $pipeline->getKey())
            ->where('position', array_search($stage, DealStage::cases(), true) + 1)
            ->firstOrFail();
    }
}
