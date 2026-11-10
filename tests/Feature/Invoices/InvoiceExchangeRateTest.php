<?php

use App\Enums\Currency;
use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Services\Invoices\InvoiceLedger;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Tnd]);
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Sami Ben Ali']);

    $this->makeInvoice = fn (array $attributes = [], ?Currency $currency = null, ?string $rate = null): Invoice => Invoice::factory()
        ->when($currency, fn ($factory) => $factory->inCurrency($currency, $rate))
        ->create(array_merge([
            'organization_id' => $this->org->id,
            'contact_id' => $this->contact->id,
            'amount' => '1000.00',
            'amount_paid' => 0,
            'status' => InvoiceStatus::Draft,
            'paid_at' => null,
        ], $attributes));
});

test('a new invoice takes the home currency and no rate while Draft', function () {
    $invoice = Invoice::create([
        'contact_id' => $this->contact->id,
        'invoice_number' => 'INV-2026-900',
        'amount' => '1000.00',
        'issued_at' => '2026-02-01',
        'due_at' => '2026-02-15',
    ])->fresh();

    expect($invoice->currency)->toBe(Currency::Tnd)
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->exchange_rate)->toBeNull();
});

test('a home-currency invoice gets rate 1 when it is issued', function () {
    $invoice = ($this->makeInvoice)();

    $invoice->update(['status' => InvoiceStatus::Sent]);

    expect($invoice->fresh()->exchange_rate)->toBe('1.00000000');
});

test('factory invoices in the home currency carry rate 1 once issued', function (InvoiceStatus $status, ?string $rate) {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'status' => $status,
    ])->fresh();

    expect($invoice->currency)->toBe(Currency::Tnd)
        ->and($invoice->exchange_rate)->toBe($rate);
})->with([
    'draft' => [InvoiceStatus::Draft, null],
    'sent' => [InvoiceStatus::Sent, '1.00000000'],
    'partial' => [InvoiceStatus::Partial, '1.00000000'],
    'paid' => [InvoiceStatus::Paid, '1.00000000'],
    'overdue' => [InvoiceStatus::Overdue, '1.00000000'],
]);

test('a home-currency draft never keeps a rate', function () {
    $invoice = ($this->makeInvoice)();

    $invoice->update(['exchange_rate' => '2']);

    expect($invoice->fresh()->exchange_rate)->toBeNull();
});

test('a foreign-currency invoice is issued with its rate', function () {
    $invoice = ($this->makeInvoice)([], Currency::Eur, '3.4');

    expect($invoice->fresh()->exchange_rate)->toBe('3.40000000');

    $invoice->update(['status' => InvoiceStatus::Sent]);

    $invoice = $invoice->fresh();

    expect($invoice->currency)->toBe(Currency::Eur)
        ->and($invoice->exchange_rate)->toBe('3.40000000');
});

test('a foreign-currency invoice can\'t be issued without a rate', function () {
    $invoice = ($this->makeInvoice)([], Currency::Usd);

    expect(fn () => $invoice->update(['status' => InvoiceStatus::Sent]))
        ->toThrow(LogicException::class, "An invoice in another currency than the organization's needs an exchange rate before it is issued.")
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);
});

test('a foreign-currency invoice can\'t be created already issued without a rate', function () {
    expect(fn () => ($this->makeInvoice)(['status' => InvoiceStatus::Sent], Currency::Usd))
        ->toThrow(LogicException::class, 'needs an exchange rate before it is issued.');

    expect(Invoice::query()->count())->toBe(0);
});

test('a foreign-currency draft can be cancelled without a rate', function () {
    $invoice = ($this->makeInvoice)([], Currency::Usd);

    $invoice->update(['status' => InvoiceStatus::Cancelled]);

    $invoice = $invoice->fresh();

    expect($invoice->status)->toBe(InvoiceStatus::Cancelled)
        ->and($invoice->exchange_rate)->toBeNull();
});

test('the currency and rate of an issued invoice are frozen', function (string $attribute, mixed $value) {
    $invoice = ($this->makeInvoice)(['status' => InvoiceStatus::Sent], Currency::Eur, '3.40000000');

    expect(fn () => $invoice->update([$attribute => $value]))
        ->toThrow(LogicException::class, "The currency and exchange rate of an issued invoice can't change.");

    $invoice = $invoice->fresh();

    expect($invoice->currency)->toBe(Currency::Eur)
        ->and($invoice->exchange_rate)->toBe('3.40000000');
})->with([
    'exchange rate' => ['exchange_rate', '3.50'],
    'currency' => ['currency', Currency::Usd],
]);

test('every issued status freezes the currency', function (InvoiceStatus $status) {
    $invoice = ($this->makeInvoice)(['status' => $status], Currency::Eur, '3.4');

    expect(fn () => $invoice->update(['exchange_rate' => '9']))->toThrow(LogicException::class);
})->with([InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Paid, InvoiceStatus::Overdue, InvoiceStatus::Cancelled]);

test('saving an issued invoice with the same rate in another notation is not a change', function () {
    $invoice = ($this->makeInvoice)(['status' => InvoiceStatus::Sent], Currency::Eur, '3.4');

    $invoice->update(['exchange_rate' => '3.40', 'notes' => 'Same rate']);

    expect($invoice->fresh()->notes)->toBe('Same rate');
});

test('other fields of an issued invoice still save, and payments keep the rate', function () {
    $invoice = ($this->makeInvoice)(['status' => InvoiceStatus::Sent], Currency::Eur, '3.40000000');

    $invoice->update(['notes' => 'Paid by wire']);

    app(InvoiceLedger::class)->record($invoice, InvoicePaymentKind::Payment, '500.00', '2026-02-21', PaymentMethod::BankTransfer, null, $this->user);

    $invoice = $invoice->fresh();

    expect($invoice->notes)->toBe('Paid by wire')
        ->and($invoice->status)->toBe(InvoiceStatus::Partial)
        ->and($invoice->amount_paid)->toBe('500.00')
        ->and($invoice->exchange_rate)->toBe('3.40000000');
});

test('an issued invoice moved back to Draft becomes editable again', function () {
    $invoice = ($this->makeInvoice)(['status' => InvoiceStatus::Sent], Currency::Eur, '3.40000000');

    $invoice->update(['status' => InvoiceStatus::Draft]);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Draft);

    $invoice->update(['exchange_rate' => '3.50000000']);

    expect($invoice->fresh()->exchange_rate)->toBe('3.50000000');
});

test('the database refuses a rate that is not positive', function (string $rate) {
    expect(fn () => DB::transaction(fn () => ($this->makeInvoice)([], Currency::Eur, $rate)))
        ->toThrow(QueryException::class, 'invoices_exchange_rate_positive_check');
})->with(['zero' => '0', 'negative' => '-1']);

test('rates are stored with 8 decimals, half up', function () {
    $invoice = ($this->makeInvoice)([], Currency::Eur, '3.123456789');

    expect($invoice->fresh()->exchange_rate)->toBe('3.12345679');
});

test('the home currency is the invoice\'s own organization, not the panel tenant', function () {
    $other = Organization::factory()->create(['currency' => Currency::Eur]);
    $otherContact = Contact::factory()->create(['organization_id' => $other->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $other->id,
        'contact_id' => $otherContact->id,
        'status' => InvoiceStatus::Sent,
    ]);

    $invoice = Invoice::query()->forOrganization($other->id)->findOrFail($invoice->id);

    expect($invoice->currency)->toBe(Currency::Eur)
        ->and($invoice->exchange_rate)->toBe('1.00000000');
});

test('the organization is resolved from the tenant context before the rules run on insert', function () {
    $other = Organization::factory()->create(['currency' => Currency::Gbp]);
    $otherContact = Contact::factory()->create(['organization_id' => $other->id]);

    Filament::setTenant(null);

    $id = app(TenantContext::class)->run($other->id, fn (): int => Invoice::create([
        'contact_id' => $otherContact->id,
        'currency' => Currency::Gbp,
        'invoice_number' => 'INV-2026-901',
        'issued_at' => '2026-02-01',
        'due_at' => '2026-02-15',
        'amount' => '10.00',
        'status' => InvoiceStatus::Sent,
    ])->id);

    $invoice = Invoice::query()->forOrganization($other->id)->findOrFail($id);

    expect($invoice->organization_id)->toBe($other->id)
        ->and($invoice->currency)->toBe(Currency::Gbp)
        ->and($invoice->exchange_rate)->toBe('1.00000000');
});
