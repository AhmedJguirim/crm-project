<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'status' => null,
            'phone' => fake()->optional(0.6)->phoneNumber(),
            'lead_source' => null,
            'custom_field_values' => [],
        ];
    }

    public function withTags(array $tagIds): static
    {
        return $this->afterCreating(function ($contact) use ($tagIds) {
            $contact->tags()->sync($tagIds);
        });
    }

    public function withCustomFields(array $values): static
    {
        return $this->state(fn (array $attributes) => [
            'custom_field_values' => $values,
        ]);
    }
}
