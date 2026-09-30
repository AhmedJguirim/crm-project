<?php

namespace Database\Factories;

use App\Models\Address;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Address>
 */
class AddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'country' => fake()->country(),
            'city' => fake()->city(),
            'street' => fake()->streetAddress(),
            'zip' => fake()->postcode(),
        ];
    }
}
