<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
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
            'deal_id' => null,
            'title' => fake()->sentence(5),
            'due_at' => fake()->optional(0.85)->dateTimeBetween('-3 days', '+10 days'),
            'type' => fake()->randomElement(TaskType::cases()),
            'priority' => fake()->randomElement(TaskPriority::cases()),
            'notes' => fake()->optional(0.8)->paragraph(),
            'status' => TaskStatus::Pending,
            'completed_at' => null,
            'created_by' => User::factory(),
        ];
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TaskStatus::Done,
            'completed_at' => now(),
        ]);
    }
}
