<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->company(),
            'notes' => fake()->optional(0.5)->sentence(),
            'custom_field_values' => [],
        ];
    }
}
