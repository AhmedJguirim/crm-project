<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\DealStageChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealStageChange>
 */
class DealStageChangeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'organization_id' => fn (array $attributes): ?int => Deal::query()->withoutGlobalScope('organization')->whereKey($attributes['deal_id'])->value('organization_id'),
            'from_pipeline_stage_id' => null,
            'to_pipeline_stage_id' => fn (array $attributes): ?int => Deal::query()->withoutGlobalScope('organization')->whereKey($attributes['deal_id'])->value('pipeline_stage_id'),
            'changed_by' => null,
            'changed_at' => now(),
        ];
    }
}
