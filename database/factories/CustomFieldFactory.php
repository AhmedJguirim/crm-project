<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CustomField>
 */
class CustomFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => \App\Models\Organization::factory(),
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

    public function multiselect(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'multiselect',
            'options' => [
                ['label' => 'Tag 1', 'value' => 'tag1'],
                ['label' => 'Tag 2', 'value' => 'tag2'],
                ['label' => 'Tag 3', 'value' => 'tag3'],
            ],
        ]);
    }

    public function text(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'text',
            'options' => null,
        ]);
    }

    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'email',
            'options' => null,
        ]);
    }
}
