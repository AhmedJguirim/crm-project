<?php

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Contact;
use App\Models\Invoice;
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

test('editing a partial invoice to paid fills the amount paid and keeps the payment date', function () {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Partial, '5600.00', '2000.00', '2026-02-10');

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['status' => InvoiceStatus::Paid])
        ->assertSet('data.amount_paid', fn ($value) => (float) $value === 5600.0)
        ->assertFormFieldIsDisabled('amount_paid')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->refresh()->amount_paid)->toBe('5600.00')
        ->and($invoice->paid_at->toDateString())->toBe('2026-02-10');
});

test('a paid invoice keeps amount paid equal to the amount when the amount changes', function () {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Paid, '1000.00', '1000.00', '2026-02-10');

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['amount' => 1500])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->refresh()->amount_paid)->toBe('1500.00');
});

test('the edit form refuses inconsistent amounts', function (InvoiceStatus $status, int|string $paid, string $message) {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Sent, '5600.00', 0);

    $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['status' => $status, 'amount_paid' => $paid])
        ->call('save');

    expect($page->errors()->get('data.amount_paid'))->toContain($message);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->amount_paid)->toBe('0.00');
})->with([
    'partial without payment' => [InvoiceStatus::Partial, 0, 'A partial invoice needs the amount already paid.'],
    'partial equal to the amount' => [InvoiceStatus::Partial, 5600, 'A partial payment must be less than the invoice amount. Set the status to Paid if the invoice is fully paid.'],
    'partial above the amount' => [InvoiceStatus::Partial, 6000, 'A partial payment must be less than the invoice amount. Set the status to Paid if the invoice is fully paid.'],
    'sent with money' => [InvoiceStatus::Sent, 100, 'Only a partial or paid invoice can have an amount paid. Set the status to Partial, or the amount paid to 0.'],
    'overdue with money' => [InvoiceStatus::Overdue, 100, 'Only a partial or paid invoice can have an amount paid. Set the status to Partial, or the amount paid to 0.'],
    'draft with money' => [InvoiceStatus::Draft, 100, 'Only a partial or paid invoice can have an amount paid. Set the status to Partial, or the amount paid to 0.'],
]);

test('the edit form accepts consistent combinations', function (InvoiceStatus $status, int|string $paid, string $stored) {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Sent, '5600.00', 0);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['status' => $status, 'amount_paid' => $paid])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($invoice->refresh()->amount_paid)->toBe($stored);
})->with([
    'partial' => [InvoiceStatus::Partial, 2000, '2000.00'],
    'partial just under the amount' => [InvoiceStatus::Partial, '5599.99', '5599.99'],
    'sent without money' => [InvoiceStatus::Sent, 0, '0.00'],
    'cancelled keeps what was paid' => [InvoiceStatus::Cancelled, 100, '100.00'],
]);

test('a forged non numeric amount paid is reported once by the numeric rule', function () {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Sent, '5600.00', 0);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['status' => InvoiceStatus::Partial, 'amount_paid' => 'abc'])
        ->call('save')
        ->assertHasFormErrors(['amount_paid' => 'numeric']);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent);
});

test('the paid date helper text follows the status', function () {
    $invoice = ($this->makePaymentInvoice)(InvoiceStatus::Partial, '5600.00', '2000.00', '2026-02-10');

    $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id]);
    $helper = function () use ($page): string {
        $field = $page->instance()->getSchema('form')->getComponent('paid_at');

        return collect($field->getChildComponents($field::BELOW_CONTENT_SCHEMA_KEY))->map(fn ($component): string => (string) $component->getContent())->implode(' ');
    };

    expect($helper())->toBe('The date of the last payment received. Today if left empty.');

    $page->fillForm(['status' => InvoiceStatus::Paid]);

    expect($helper())->toBe('The date the invoice was fully paid. Today if left empty.');
});

test('mark as paid stamps today and fills the amount paid', function () {
    $viewed = ($this->makePaymentInvoice)(InvoiceStatus::Partial, '5600.00', '2000.00', '2026-02-10');
    $listed = ($this->makePaymentInvoice)(InvoiceStatus::Partial, '5600.00', '2000.00', '2026-02-10');

    Livewire::test(ViewInvoice::class, ['record' => $viewed->id])->callAction('markAsPaid');
    Livewire::test(ListInvoices::class)->callAction(TestAction::make('markAsPaid')->table($listed));

    foreach ([$viewed, $listed] as $invoice) {
        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and($invoice->amount_paid)->toBe('5600.00')
            ->and($invoice->paid_at->toDateString())->toBe('2026-02-21');
    }
});
