<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'contact_id' => null,
            'title' => fake()->sentence(3),
            'pipeline_stage_id' => fn (array $attributes): ?int => $attributes['organization_id'] === null
                ? null
                : DefaultPipeline::stageOfType($attributes['organization_id'], DealStatus::Open)->getKey(),
            'stage' => fn (array $attributes): mixed => $attributes['pipeline_stage_id'] === null
                ? null
                : DefaultPipeline::legacyStageFor(PipelineStage::forOrganization($attributes['organization_id'])->findOrFail($attributes['pipeline_stage_id'])),
            'value' => fake()->optional(0.8)->randomFloat(2, 500, 150000),
            'expected_close_date' => fake()->optional(0.75)->dateTimeBetween('-2 weeks', '+8 weeks'),
            'notes' => fake()->optional(0.6)->paragraph(),
            'status' => DealStatus::Open,
            'won_at' => null,
            'lost_at' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Puts the deal in the stage of its organization's default pipeline with this name (the status and dates are not touched).
     *
     * @throws InvalidArgumentException
     */
    public function inStage(string $name): static
    {
        return $this->state([
            'pipeline_stage_id' => fn (array $attributes): int => DefaultPipeline::stagesOf($attributes['organization_id'])->firstWhere('name', $name)?->getKey()
                ?? throw new InvalidArgumentException("No pipeline stage named \"{$name}\"."),
        ]);
    }
}
