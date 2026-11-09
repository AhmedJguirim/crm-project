<?php

namespace Database\Factories;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Activity> */
class ActivityFactory extends Factory
{
    protected $model = Activity::class;

    public function definition(): array
    {
        $type = fake()->randomElement(ActivityType::cases());

        return [
            'organization_id' => Organization::factory(),
            'contact_id' => fn (array $attributes) => Contact::factory()->state(['organization_id' => $attributes['organization_id']]),
            'user_id' => User::factory(),
            'type' => $type,
            'occurred_at' => fake()->dateTimeBetween('-90 days', 'now'),
            'duration_minutes' => $type->hasDuration() ? fake()->numberBetween(5, 120) : null,
            'subject' => fake()->optional(0.6)->sentence(4),
            'notes' => fake()->optional(0.7)->paragraph(),
            'outcome' => fake()->optional(0.5)->randomElement(ActivityOutcome::cases()),
        ];
    }

    public function type(ActivityType $type): static
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'duration_minutes' => $type->hasDuration() ? fake()->numberBetween(5, 120) : null,
        ]);
    }

    public function withOutcome(ActivityOutcome $outcome): static
    {
        return $this->state(fn (): array => ['outcome' => $outcome]);
    }

    public function onlyOnDeal(Deal $deal): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $deal->organization_id,
            'contact_id' => null,
            'deal_id' => $deal->id,
            'company_id' => null,
        ]);
    }

    public function onlyOnCompany(Company $company): static
    {
        return $this->state(fn (): array => [
            'organization_id' => $company->organization_id,
            'contact_id' => null,
            'deal_id' => null,
            'company_id' => $company->id,
        ]);
    }
}
