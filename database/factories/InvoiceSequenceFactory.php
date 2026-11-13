<?php

namespace Database\Factories;

use App\Models\InvoiceSequence;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoiceSequence>
 */
class InvoiceSequenceFactory extends Factory
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
            'year' => 2026,
            'last_sequence' => 1,
        ];
    }
}
