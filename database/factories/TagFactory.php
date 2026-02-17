<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tag>
 */
class TagFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->word(),
            'color' => fake()->optional(0.6)->hexColor(),
        ];
    }

    public function withColor(): static
    {
        return $this->state(fn (array $attributes) => [
            'color' => fake()->hexColor(),
        ]);
    }

    public function withoutColor(): static
    {
        return $this->state(fn (array $attributes) => [
            'color' => null,
        ]);
    }
}
