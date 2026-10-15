<?php

namespace Database\Factories;

use App\Enums\DealStatus;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineStage>
 */
class PipelineStageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'organization_id' => fn (array $attributes): int => Pipeline::query()
                ->withoutGlobalScope('organization')
                ->whereKey($attributes['pipeline_id'])
                ->value('organization_id'),
            'name' => fake()->unique()->word(),
            'type' => DealStatus::Open,
            'color' => 'gray',
            'position' => fake()->numberBetween(1, 100),
            'probability' => 50,
        ];
    }

    public function won(): static
    {
        return $this->state(['type' => DealStatus::Won, 'probability' => 100]);
    }

    public function lost(): static
    {
        return $this->state(['type' => DealStatus::Lost, 'probability' => 0]);
    }
}
