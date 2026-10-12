<?php

namespace Database\Factories;

use App\Data\Segments\SegmentRuleData;
use App\Models\Organization;
use App\Models\Segment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
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
            'name' => fake()->unique()->words(3, true),
            'rules' => [],
            'draft_rules' => null,
            'is_published' => false,
            'is_syncing' => false,
            'last_synced_at' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_published' => true,
            'last_synced_at' => now(),
        ]);
    }

    /** @param  array<int, SegmentRuleData>  $rules */
    public function withRules(array $rules): static
    {
        return $this->state(fn (array $attributes): array => [
            'rules' => collect($rules)->map(fn (SegmentRuleData $rule): array => $rule->toArray())->all(),
        ]);
    }
}
