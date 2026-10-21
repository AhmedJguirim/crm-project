<?php

namespace Database\Factories;

use App\Enums\InvoicePaymentKind;
use App\Enums\PaymentMethod;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InvoicePayment>
 */
class InvoicePaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'organization_id' => fn (array $attributes): ?int => Invoice::query()->withoutGlobalScope('organization')->whereKey($attributes['invoice_id'])->value('organization_id'),
            'kind' => InvoicePaymentKind::Payment,
            'amount' => '100.00',
            'paid_on' => now()->toDateString(),
            'method' => PaymentMethod::BankTransfer,
            'reference' => null,
            'recorded_by_id' => null,
        ];
    }

    public function refund(): static
    {
        return $this->state(['kind' => InvoicePaymentKind::Refund]);
    }
}
