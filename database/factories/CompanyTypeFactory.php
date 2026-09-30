<?php

namespace Database\Factories;

use App\Models\CompanyType;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyType>
 */
class CompanyTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->word(),
        ];
    }
}
