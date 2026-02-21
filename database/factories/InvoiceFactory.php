<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Contact;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $status = fake()->randomElement(InvoiceStatus::cases());
        $paidAt = $status === InvoiceStatus::Paid
            ? fake()->dateTimeBetween('-30 days', 'now')
            : null;

        return [
            'organization_id' => Organization::factory(),
            'contact_id' => Contact::factory(),
            'deal_id' => null,
            'invoice_number' => sprintf('INV-%s-%03d', now()->year, fake()->unique()->numberBetween(1, 999)),
            'amount' => fake()->randomFloat(2, 100, 25000),
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP', 'CAD', 'AUD']),
            'status' => $status,
            'issued_at' => fake()->dateTimeBetween('-45 days', 'now'),
            'due_at' => fake()->dateTimeBetween('-10 days', '+30 days'),
            'paid_at' => $paidAt,
            'notes' => fake()->optional(0.7)->sentence(),
        ];
    }
}
