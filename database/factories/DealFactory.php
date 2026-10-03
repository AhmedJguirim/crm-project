<?php

namespace Database\Factories;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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
            'stage' => fake()->randomElement(DealStage::cases()),
            'value' => fake()->optional(0.8)->randomFloat(2, 500, 150000),
            'currency' => 'USD',
            'expected_close_date' => fake()->optional(0.75)->dateTimeBetween('-2 weeks', '+8 weeks'),
            'notes' => fake()->optional(0.6)->paragraph(),
            'status' => DealStatus::Open,
            'won_at' => null,
            'lost_at' => null,
            'created_by' => User::factory(),
        ];
    }
}
