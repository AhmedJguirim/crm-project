<?php

namespace Database\Factories;

use App\Enums\Currency;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * The slug is derived from the final name and gets a random suffix, so two factory organizations never collide on the unique slug index.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fn (array $attributes): string => Str::slug($attributes['name']).'-'.Str::lower(Str::random(6)),
            'description' => fake()->optional()->sentence(),
            'personal_team' => false,
            'currency' => Currency::Usd,
            'created_by' => User::factory(),
        ];
    }

    public function timezone(string $timezone): static
    {
        return $this->state(fn (array $attributes) => [
            'timezone' => $timezone,
        ]);
    }

    public function personal(): static
    {
        return $this->state(fn (array $attributes) => [
            'personal_team' => true,
        ]);
    }
}
