<?php

use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Exceptions\InvoicePaymentRefused;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Services\Invoices\InvoiceLedger;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 12:00:00');

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'invoice_number' => 'INV-2026-001',
        'amount' => '5600.00',
        'amount_paid' => 0,
        'status' => InvoiceStatus::Sent,
        'due_at' => '2026-02-26',
        'paid_at' => null,
    ]);

    $this->ledger = fn (InvoicePaymentKind $kind, string $amount, string $paidOn = '2026-02-20', ?Invoice $invoice = null): InvoicePayment => app(InvoiceLedger::class)->record(
        $invoice ?? $this->invoice, $kind, $amount, $paidOn, null, null, $this->owner,
    );

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

test('recording a partial payment stores the entry and notifies', function () {
    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', data: ['amount' => '2000.00', 'paid_on' => '2026-02-20', 'method' => PaymentMethod::BankTransfer->value, 'reference' => 'VIR-123'])
        ->assertHasNoActionErrors()
        ->assertNotified('Payment recorded');

    $entry = InvoicePayment::query()->sole();

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Partial)
        ->and($this->invoice->amount_paid)->toBe('2000.00')
        ->and($this->invoice->paid_at->toDateString())->toBe('2026-02-20')
        ->and($entry->kind)->toBe(InvoicePaymentKind::Payment)
        ->and($entry->amount)->toBe('2000.00')
        ->and($entry->method)->toBe(PaymentMethod::BankTransfer)
        ->and($entry->reference)->toBe('VIR-123')
        ->and($entry->recorded_by_id)->toBe($this->owner->id);
});

test('the payment modal is prefilled with the balance and today and completes the invoice', function () {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->mountAction('recordPayment')
        ->assertActionDataSet(['amount' => '3600.00', 'paid_on' => '2026-02-21', 'method' => null, 'reference' => null])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->amount_paid)->toBe('5600.00')
        ->and($this->invoice->paid_at->toDateString())->toBe('2026-02-21');
});

test('a payment above the balance shows the balance in the amount error', function () {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');

    $page = Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', data: ['amount' => '3600.01', 'paid_on' => '2026-02-21'])
        ->assertHasActionErrors(['amount']);

    expect($page->errors()->get('mountedActions.0.data.amount'))->toContain("The payment can't be more than the balance due (\$3,600.00).")
        ->and($this->invoice->payments()->count())->toBe(1);
});

test('invalid payment fields are refused', function (string $field, mixed $value, string $rule) {
    $data = ['amount' => '100.00', 'paid_on' => '2026-02-21', 'method' => null, 'reference' => null, $field => $value];

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordPayment', data: $data)
        ->assertHasActionErrors([$field => $rule]);

    expect(InvoicePayment::query()->count())->toBe(0);
})->with([
    'amount zero' => ['amount', '0', 'min'],
    'amount empty' => ['amount', '', 'required'],
    'amount text' => ['amount', 'abc', 'numeric'],
    'date in the future' => ['paid_on', '2026-02-22', 'before_or_equal'],
    'date empty' => ['paid_on', '', 'required'],
    'forged method' => ['method', 'bitcoin', 'The selected method is invalid.'],
    'reference too long' => ['reference', str_repeat('a', 256), 'max'],
]);

test('the largest reference and amount the columns hold are accepted', function () {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->invoice->contact_id,
        'amount' => '99999999.99',
        'amount_paid' => 0,
        'status' => InvoiceStatus::Sent,
    ]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->callAction('recordPayment', data: ['amount' => '99999999.99', 'paid_on' => '2026-02-21', 'reference' => str_repeat('a', 255)])
        ->assertHasNoActionErrors();

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_paid)->toBe('99999999.99');
});

test('the form lets a stale balance through and the service refuses it under lock', function () {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
    $this->invoice->refresh();
    $this->invoice->update(['status' => InvoiceStatus::Partial]);

    $page = Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->mountAction('recordPayment');

    ($this->ledger)(InvoicePaymentKind::Payment, '3600.00', '2026-02-21');

    $page->setActionData(['amount' => '3600.00', 'paid_on' => '2026-02-21'])->callMountedAction();

    expect($this->invoice->refresh()->amount_paid)->toBe('5600.00')
        ->and($this->invoice->payments()->count())->toBe(2);
});

test('a refusal by the service is a danger notification with its message and records nothing', function (string $action, string $title) {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');

    $this->mock(InvoiceLedger::class)
        ->shouldReceive('record')->once()->andThrow(new InvoicePaymentRefused('This invoice no longer accepts payments.'));

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction($action, data: ['amount' => '100.00', 'paid_on' => '2026-02-21'])
        ->assertNotified(Notification::make()->danger()->title($title)->body('This invoice no longer accepts payments.'));

    expect($this->invoice->payments()->count())->toBe(1);
})->with([
    'payment' => ['recordPayment', 'Payment not recorded'],
    'refund' => ['recordRefund', 'Refund not recorded'],
]);

test('a partial refund on a paid invoice', function () {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
    ($this->ledger)(InvoicePaymentKind::Payment, '3600.00', '2026-02-21');

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordRefund', data: ['amount' => '1000.00', 'paid_on' => '2026-02-21'])
        ->assertHasNoActionErrors()
        ->assertNotified('Refund recorded');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Partial)
        ->and($this->invoice->amount_paid)->toBe('4600.00')
        ->and($this->invoice->paid_at->toDateString())->toBe('2026-02-21')
        ->and($this->invoice->payments()->where('kind', InvoicePaymentKind::Refund)->count())->toBe(1);
});

test('the refund modal is prefilled with the amount paid and refuses more', function () {
    ($this->ledger)(InvoicePaymentKind::Payment, '4600.00');

    $page = Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->mountAction('recordRefund')
        ->assertActionDataSet(['amount' => '4600.00', 'paid_on' => '2026-02-21']);

    $page->setActionData(['amount' => '4600.01', 'paid_on' => '2026-02-21'])->callMountedAction()->assertHasActionErrors(['amount']);

    expect($page->errors()->get('mountedActions.0.data.amount'))->toContain("The refund can't be more than the amount paid (\$4,600.00).")
        ->and($this->invoice->payments()->count())->toBe(1);
});

test('a full refund hides the refund action and a refund on a cancelled invoice keeps it cancelled', function () {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->callAction('recordRefund', data: ['amount' => '2000.00', 'paid_on' => '2026-02-21'])
        ->assertActionHidden('recordRefund');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($this->invoice->amount_paid)->toBe('0.00')
        ->and($this->invoice->paid_at)->toBeNull();

    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
    $this->invoice->update(['status' => InvoiceStatus::Cancelled]);

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->assertActionHidden('recordPayment')
        ->callAction('recordRefund', data: ['amount' => '2000.00', 'paid_on' => '2026-02-21']);

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($this->invoice->amount_paid)->toBe('0.00');
});

test('who sees the payment and refund actions', function (OrganizationRole $role, bool $payment, bool $refund) {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
    $this->invoice->update(['status' => InvoiceStatus::Partial]);
    ($this->actAs)($role);

    $page = Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id]);

    $payment ? $page->assertActionVisible('recordPayment') : $page->assertActionHidden('recordPayment');
    $refund ? $page->assertActionVisible('recordRefund') : $page->assertActionHidden('recordRefund');
})->with([
    'viewer' => [OrganizationRole::Viewer, false, false],
    'member' => [OrganizationRole::Member, true, false],
    'admin' => [OrganizationRole::Admin, true, true],
    'owner' => [OrganizationRole::Owner, true, true],
]);

test('a member cannot force a refund or a viewer a payment', function () {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
    $this->invoice->update(['status' => InvoiceStatus::Partial]);

    ($this->actAs)(OrganizationRole::Member);
    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->call('mountAction', 'recordRefund', [], [])
        ->assertActionNotMounted()
        ->call('callMountedAction');

    ($this->actAs)(OrganizationRole::Viewer);
    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->call('mountAction', 'recordPayment', [], [])
        ->assertActionNotMounted()
        ->call('callMountedAction');

    expect($this->invoice->payments()->count())->toBe(1)
        ->and($this->invoice->refresh()->amount_paid)->toBe('2000.00');
});

test('an invoice with payments cannot be deleted from the edit page or in bulk', function () {
    $free = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->invoice->contact_id,
        'status' => InvoiceStatus::Draft,
    ]);
    ($this->ledger)(InvoicePaymentKind::Payment, '100.00');

    expect($this->owner->can('delete', $this->invoice))->toBeFalse()
        ->and($this->owner->can('delete', $free))->toBeTrue();

    Livewire::test(EditInvoice::class, ['record' => $this->invoice->id])->assertActionHidden('delete');
    Livewire::test(EditInvoice::class, ['record' => $free->id])->assertActionVisible('delete');

    Livewire::test(ListInvoices::class)
        ->selectTableRecords([$this->invoice->id, $free->id])
        ->callAction(TestAction::make('delete')->table()->bulk());

    expect(Invoice::query()->whereKey($free->id)->exists())->toBeFalse()
        ->and(Invoice::query()->whereKey($this->invoice->id)->exists())->toBeTrue()
        ->and($this->invoice->payments()->count())->toBe(1);
});

test('the view page lists payments and refunds in date order with the reference escaped', function () {
    InvoicePayment::factory()->create(['invoice_id' => $this->invoice->id, 'amount' => '500.00', 'paid_on' => '2026-02-21', 'kind' => InvoicePaymentKind::Refund, 'method' => PaymentMethod::Cash]);
    InvoicePayment::factory()->create(['invoice_id' => $this->invoice->id, 'amount' => '2000.00', 'paid_on' => '2026-02-20', 'reference' => '<script>alert(1)</script>', 'recorded_by_id' => $this->owner->id]);
    $this->invoice->update(['status' => InvoiceStatus::Partial, 'amount_paid' => '1500.00']);

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->assertSee('Payments')
        ->assertSeeInOrder(['Payment', '$2,000.00', 'Refund', '-$500.00'])
        ->assertSee('<script>alert(1)</script>')
        ->assertSee($this->owner->name)
        ->assertDontSee('<script>alert(1)</script>', false);
});

test('the payments section is hidden on an invoice without entries', function () {
    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->assertDontSee('Recorded by');
});

test('a cancelled invoice that still holds money keeps its status whatever the edit form sends', function (OrganizationRole $role) {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
    $this->invoice->update(['status' => InvoiceStatus::Cancelled]);
    ($this->actAs)($role);

    $page = Livewire::test(EditInvoice::class, ['record' => $this->invoice->id])
        ->assertFormFieldIsDisabled('status')
        ->fillForm(['status' => InvoiceStatus::Draft->value])
        ->call('save');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($this->invoice->amount_paid)->toBe('2000.00')
        ->and($this->invoice->payments()->count())->toBe(1);
})->with([OrganizationRole::Member, OrganizationRole::Admin]);

test('the status is editable again after a full refund of a cancelled invoice', function () {
    ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
    $this->invoice->update(['status' => InvoiceStatus::Cancelled]);
    ($this->ledger)(InvoicePaymentKind::Refund, '2000.00', '2026-02-21');

    $page = Livewire::test(EditInvoice::class, ['record' => $this->invoice->id])
        ->assertFormFieldIsEnabled('status');

    expect(array_keys($page->instance()->getSchema('form')->getComponent('status')->getOptions()))->toBe(['draft', 'sent', 'overdue', 'cancelled']);

    $page->fillForm(['status' => InvoiceStatus::Draft->value])->call('save')->assertHasNoFormErrors();

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});
