<?php

use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->makePaymentInvoice = fn (InvoiceStatus $status, float|int|string $amount, float|int|string $paid, ?string $paidAt = null): Invoice => Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'status' => $status,
        'amount' => $amount,
        'amount_paid' => $paid,
        'paid_at' => $paidAt,
        'due_at' => '2026-02-26',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a paid invoice is saved as fully paid with a paid date', function () {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Paid, '24000.00', 0);

    expect($invoice->refresh()->amount_paid)->toBe('24000.00')
        ->and($invoice->paid_at->toDateString())->toBe('2026-02-21');
});

test('statuses other than paid and partial have no paid date', function (InvoiceStatus $status) {
    $invoice = ($this->makePaymentInvoice)($status, 1000, 0, '2026-02-10');

    expect($invoice->refresh()->paid_at)->toBeNull();
})->with([InvoiceStatus::Draft, InvoiceStatus::Sent, InvoiceStatus::Overdue, InvoiceStatus::Cancelled]);

test('a partial invoice keeps the last payment date and defaults to today', function () {
    $given = ($this->makePaymentInvoice)(InvoiceStatus::Partial, '5600.00', '2000.00', '2026-02-10');
    $empty = ($this->makePaymentInvoice)(InvoiceStatus::Partial, '5600.00', '2000.00');

    expect($given->refresh()->paid_at->toDateString())->toBe('2026-02-10')
        ->and($empty->refresh()->paid_at->toDateString())->toBe('2026-02-21');
});

test('a cleared amount paid is saved as zero', function () {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Sent, 1000, 0);

    $invoice->amount_paid = null;
    $invoice->save();

    expect($invoice->refresh()->amount_paid)->toBe('0.00');
});

test('the edit form no longer has the amount paid and the paid date', function () {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Sent, '5600.00', 0);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertFormFieldDoesNotExist('amount_paid')
        ->assertFormFieldDoesNotExist('paid_at');
});

test('the status options leave out paid and partial on an unpaid invoice', function () {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Sent, '5600.00', 0);

    $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id]);
    $options = $page->instance()->getSchema('form')->getComponent('status')->getOptions();

    expect(array_keys($options))->toBe(['draft', 'sent', 'overdue', 'cancelled'])
        ->and($options['sent'])->toBe('Sent');
});

test('a forged paid or partial status is refused by the in rule', function (InvoiceStatus $status) {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Sent, '5600.00', 0);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['status' => $status])
        ->call('save')
        ->assertHasFormErrors(['status' => 'in']);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->amount_paid)->toBe('0.00');
})->with([InvoiceStatus::Paid, InvoiceStatus::Partial]);

test('a partial or paid invoice has its status locked and shows the payments helper', function (InvoiceStatus $status) {
    $invoice = ($this->makePaymentInvoice)($status, '5600.00', '2000.00', '2026-02-10');
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => '2000.00', 'paid_on' => '2026-02-10']);

    $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertFormFieldIsDisabled('status')
        ->assertFormFieldIsDisabled('amount');

    $field = $page->instance()->getSchema('form')->getComponent('status');
    $helper = collect($field->getChildComponents($field::BELOW_CONTENT_SCHEMA_KEY))->map(fn ($component): string => (string) $component->getContent())->implode(' ');

    expect($helper)->toBe('Set by the payments recorded.')
        ->and(array_keys($field->getOptions()))->toBe(['draft', 'sent', 'paid', 'partial', 'overdue', 'cancelled']);

    $page->call('save')->assertHasNoFormErrors();

    expect($invoice->refresh()->status)->toBe($status)
        ->and($invoice->amount)->toBe('5600.00');
})->with([InvoiceStatus::Paid, InvoiceStatus::Partial]);

test('the amount stays editable until a payment is recorded', function () {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Sent, '5600.00', 0);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertFormFieldIsEnabled('amount')
        ->fillForm(['amount' => 6000])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->refresh()->amount)->toBe('6000.00');
});

test('recording the balance from the view page and the table marks the invoice paid today', function () {
    $viewed = ($this->makePaymentInvoice)(InvoiceStatus::Partial, '5600.00', '2000.00', '2026-02-10');
    $listed = ($this->makePaymentInvoice)(InvoiceStatus::Partial, '5600.00', '2000.00', '2026-02-10');

    foreach ([$viewed, $listed] as $invoice) {
        InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => '2000.00', 'paid_on' => '2026-02-10']);
    }

    Livewire::test(ViewInvoice::class, ['record' => $viewed->id])->callAction('recordPayment');
    Livewire::test(ListInvoices::class)->callAction(TestAction::make('recordPayment')->table($listed));

    foreach ([$viewed, $listed] as $invoice) {
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->amount_paid)->toBe('5600.00')
            ->and($invoice->paid_at->toDateString())->toBe('2026-02-21')
            ->and($invoice->payments()->where('kind', InvoicePaymentKind::Payment)->count())->toBe(2);
    }
});
