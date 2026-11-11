<?php

use App\Enums\Currency;
use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvoicePaymentRefused;
use App\Filament\Resources\Deals\Widgets\DealInvoicesWidget;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Widgets\OverdueInvoicesWidget;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Services\Invoices\InvoiceLedger;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function ownCurrencyFormField(Testable $page, string $name): Field
{
    return collect($page->instance()->getSchema('form')->getFlatComponents())
        ->first(fn ($component): bool => $component instanceof Field && $component->getName() === $name);
}

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Tnd]);
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Sami Ben Ali']);
    $this->nbsp = "\u{00A0}";

    $this->eurInvoice = Invoice::factory()->inCurrency(Currency::Eur, '3.4')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'invoice_number' => 'INV-EUR',
        'status' => InvoiceStatus::Sent,
        'amount' => 1000,
        'amount_paid' => 0,
        'paid_at' => null,
    ]);

    app(InvoiceLedger::class)->record(
        $this->eurInvoice,
        InvoicePaymentKind::Payment,
        '400.00',
        '2026-11-02',
        PaymentMethod::BankTransfer,
        null,
        $this->user,
    );

    $this->eurInvoice->refresh();
});

test('the invoices list shows the invoice currency', function () {
    Livewire::test(ListInvoices::class)
        ->assertSee('€1,000.00')
        ->assertDontSee("TND{$this->nbsp}1,000.00")
        ->assertDontSee("TND{$this->nbsp}1,000.000");
});

test('the invoice page shows amounts, balance, payments and the rate in the invoice currency', function () {
    expect($this->eurInvoice->status)->toBe(InvoiceStatus::Partial);

    Livewire::test(ViewInvoice::class, ['record' => $this->eurInvoice->id])
        ->assertSee('€1,000.00')
        ->assertSee('€400.00')
        ->assertSee('600.00 EUR')
        ->assertSee('Exchange rate')
        ->assertSee('1 EUR = 3.4 TND')
        ->assertDontSee("TND{$this->nbsp}400");
});

test('a refund row of the payments table is negative and in the invoice currency', function () {
    app(InvoiceLedger::class)->record($this->eurInvoice, InvoicePaymentKind::Refund, '100.00', '2026-11-03', null, null, $this->user);

    Livewire::test(ViewInvoice::class, ['record' => $this->eurInvoice->id])
        ->assertSee('€400.00')
        ->assertSee('-€100.00');
});

test('the payments of an invoice with several rows render without a lazy load of the invoice', function () {
    app(InvoiceLedger::class)->record($this->eurInvoice, InvoicePaymentKind::Payment, '50.00', '2026-11-04', null, null, $this->user);

    expect(InvoicePayment::query()->where('invoice_id', $this->eurInvoice->id)->count())->toBe(2);

    Livewire::test(ViewInvoice::class, ['record' => $this->eurInvoice->id])
        ->assertSee('€400.00')
        ->assertSee('€50.00');
});

test('a home-currency invoice uses 2 decimals and shows no exchange rate', function () {
    $invoice = Invoice::factory()->inCurrency(Currency::Tnd, '1')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'status' => InvoiceStatus::Sent,
        'amount' => 1000,
        'amount_paid' => 0,
        'paid_at' => null,
    ]);

    expect($invoice->refresh()->exchange_rate)->toBe('1.00000000');

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertSee("TND{$this->nbsp}1,000.00")
        ->assertDontSee("TND{$this->nbsp}1,000.000")
        ->assertDontSee('Exchange rate');
});

test('a foreign-currency draft without a rate shows no exchange rate', function () {
    $invoice = Invoice::factory()->inCurrency(Currency::Usd)->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'status' => InvoiceStatus::Draft,
        'amount' => 250,
        'amount_paid' => 0,
        'paid_at' => null,
    ]);

    expect($invoice->exchange_rate)->toBeNull();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertSee('$250.00')
        ->assertDontSee('Exchange rate');
});

test('the edit form and the payment modal prefix the amount with the invoice currency', function () {
    $edit = Livewire::test(EditInvoice::class, ['record' => $this->eurInvoice->id]);

    expect(ownCurrencyFormField($edit, 'amount')->getPrefixLabel())->toBe('EUR');

    $create = Livewire::test(CreateInvoice::class);

    expect(ownCurrencyFormField($create, 'amount')->getPrefixLabel())->toBe('TND');
});

test('the payment and refund refusals name the invoice currency', function (string $action, string $amount, string $message) {
    $page = Livewire::test(ViewInvoice::class, ['record' => $this->eurInvoice->id])
        ->callAction($action, data: ['amount' => $amount, 'paid_on' => today()->toDateString()])
        ->assertHasActionErrors(['amount']);

    expect($page->errors()->get('mountedActions.0.data.amount'))->toContain($message);
})->with([
    'payment' => ['recordPayment', '600.01', "The payment can't be more than the balance due (€600.00)."],
    'refund' => ['recordRefund', '400.01', "The refund can't be more than the amount paid (€400.00)."],
]);

test('the ledger refusal itself uses the invoice currency', function (InvoicePaymentKind $kind, string $amount, string $message) {
    expect(fn () => app(InvoiceLedger::class)->record($this->eurInvoice, $kind, $amount, '2026-11-03', null, null, $this->user))
        ->toThrow(InvoicePaymentRefused::class, $message);
})->with([
    'payment' => [InvoicePaymentKind::Payment, '700.00', "The payment can't be more than the balance due (€600.00)."],
    'refund' => [InvoicePaymentKind::Refund, '700.00', "The refund can't be more than the amount paid (€400.00)."],
]);

test('the overdue invoices widget shows the balance in the invoice currency', function () {
    $this->travelTo('2026-11-11 12:00:00');
    $this->eurInvoice->update(['status' => InvoiceStatus::Overdue, 'due_at' => '2026-10-01']);

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertSee('€600.00')
        ->assertDontSee("TND{$this->nbsp}600");
});

test('the deal invoices widget rows use the invoice currency', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Site refonte',
    ]);
    $this->eurInvoice->update(['deal_id' => $deal->id]);

    Livewire::test(DealInvoicesWidget::class, ['record' => $deal])
        ->assertSee('INV-EUR')
        ->assertSee('€1,000.00');
});

test('the invoice pdf shows only the invoice currency', function () {
    $html = view('pdf.invoice', [
        'invoice' => $this->eurInvoice->load(['contact', 'deal', 'organization']),
        'organization' => $this->org,
    ])->render();

    expect($html)->toContain('€1,000.00')
        ->toContain('- €400.00')
        ->toContain('€600.00')
        ->not->toContain('TND')
        ->not->toContain('1 EUR =');
});

test('formatMoney takes numbers, numeric strings and null in the invoice currency with 2 decimals', function () {
    $tnd = Invoice::factory()->inCurrency(Currency::Tnd)->make();

    expect($this->eurInvoice->formatMoney('1000.5'))->toBe('€1,000.50')
        ->and($this->eurInvoice->formatMoney(7))->toBe('€7.00')
        ->and($this->eurInvoice->formatMoney(null))->toBe('€0.00')
        ->and($tnd->formatMoney(1000))->toBe("TND{$this->nbsp}1,000.00");
});

test('another organization invoice currency never leaks into this organization screens', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $other->personalOrganization();
    $otherContact = Contact::factory()->create(['organization_id' => $otherOrg->id]);
    Invoice::factory()->inCurrency(Currency::Usd)->create([
        'organization_id' => $otherOrg->id,
        'contact_id' => $otherContact->id,
        'invoice_number' => 'INV-OTHER',
        'status' => InvoiceStatus::Draft,
        'amount' => 4321,
    ]);

    Livewire::test(ListInvoices::class)
        ->assertDontSee('INV-OTHER')
        ->assertDontSee('$4,321.00');
});

test('the payment and refund modals prefix the amount with the invoice currency', function (string $action) {
    $page = Livewire::test(ViewInvoice::class, ['record' => $this->eurInvoice->id])->mountAction($action);

    $field = collect($page->instance()->getSchema($page->instance()->getMountedActionSchemaName())->getFlatComponents())
        ->first(fn ($component): bool => $component instanceof Field && $component->getName() === 'amount');

    expect($field->getPrefixLabel())->toBe('EUR');
})->with(['recordPayment', 'recordRefund']);

test('a home-currency TND invoice shows 2 decimals on the list, overdue widget, deal widget row and pdf', function () {
    $this->travelTo('2026-11-11 12:00:00');

    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Tunis retainer',
    ]);

    $tnd = Invoice::factory()->inCurrency(Currency::Tnd, '1')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'deal_id' => $deal->id,
        'invoice_number' => 'INV-TND',
        'status' => InvoiceStatus::Overdue,
        'amount' => 1000,
        'amount_paid' => 0,
        'paid_at' => null,
        'due_at' => '2026-10-01',
    ]);

    $good = "TND{$this->nbsp}1,000.00";
    $bad = "TND{$this->nbsp}1,000.000";

    Livewire::test(ListInvoices::class)
        ->assertSee('INV-TND')
        ->assertSee($good)
        ->assertDontSee($bad);

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertSee('INV-TND')
        ->assertSee($good)
        ->assertDontSee($bad);

    Livewire::test(DealInvoicesWidget::class, ['record' => $deal])
        ->assertSee('INV-TND')
        ->assertSee($good);

    $html = view('pdf.invoice', [
        'invoice' => $tnd->load(['contact', 'deal', 'organization']),
        'organization' => $this->org,
    ])->render();

    expect($html)->toContain($good)->not->toContain($bad);
});
