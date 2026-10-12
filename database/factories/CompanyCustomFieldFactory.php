<?php

namespace Database\Factories;

use App\Models\CompanyCustomField;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyCustomField>
 */
class CompanyCustomFieldFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => fake()->randomElement(['text', 'email', 'url', 'phone', 'number', 'date', 'textarea']),
            'unique' => fake()->boolean(30),
            'order' => 1,
            'options' => null,
        ];
    }

    public function select(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'select',
            'options' => [
                ['label' => 'Option 1', 'value' => 'opt1'],
                ['label' => 'Option 2', 'value' => 'opt2'],
                ['label' => 'Option 3', 'value' => 'opt3'],
            ],
        ]);
    }
}
