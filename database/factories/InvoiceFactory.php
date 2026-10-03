<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        $status = fake()->randomElement(InvoiceStatus::cases());
        $paidAt = $status === InvoiceStatus::Paid
            ? fake()->dateTimeBetween('-30 days', 'now')
            : null;

        $amount = fake()->randomFloat(2, 100, 25000);
        $amountPaid = match ($status) {
            InvoiceStatus::Paid => $amount,
            InvoiceStatus::Partial => fake()->randomFloat(2, 10, $amount - 10),
            default => 0,
        };
        $paymentTerms = fake()->randomElement([7, 14, 15, 30, 45, 60, 90, 0]);

        return [
            'organization_id' => Organization::factory(),
            'contact_id' => Contact::factory(),
            'deal_id' => null,
            'invoice_number' => sprintf('INV-%s-%03d', now()->year, fake()->unique()->numberBetween(1, 999)),
            'amount' => $amount,
            'amount_paid' => $amountPaid,
            'currency' => fake()->randomElement(['USD', 'EUR', 'GBP', 'CAD', 'AUD']),
            'payment_terms' => $paymentTerms,
            'status' => $status,
            'issued_at' => fake()->dateTimeBetween('-45 days', 'now'),
            'due_at' => fake()->dateTimeBetween('-10 days', '+30 days'),
            'paid_at' => $paidAt,
            'notes' => fake()->optional(0.7)->sentence(),
        ];
    }
}
