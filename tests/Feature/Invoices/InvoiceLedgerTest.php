<?php

use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\InvoicePaymentRefused;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Invoices\InvoiceLedger;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
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

    $this->record = fn (InvoicePaymentKind $kind, string $amount, string $paidOn = '2026-02-21', ?Invoice $invoice = null): InvoicePayment => app(InvoiceLedger::class)->record(
        $invoice ?? $this->invoice, $kind, $amount, $paidOn, PaymentMethod::BankTransfer, null, $this->user,
    );
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a partial payment makes the invoice partial and keeps the entry', function () {
    $entry = app(InvoiceLedger::class)->record($this->invoice, InvoicePaymentKind::Payment, '2000.00', '2026-02-20', PaymentMethod::BankTransfer, 'VIR-123', $this->user);

    expect($this->invoice->status)->toBe(InvoiceStatus::Partial)
        ->and($this->invoice->refresh()->amount_paid)->toBe('2000.00')
        ->and($this->invoice->status)->toBe(InvoiceStatus::Partial)
        ->and($this->invoice->paid_at->toDateString())->toBe('2026-02-20')
        ->and($entry->kind)->toBe(InvoicePaymentKind::Payment)
        ->and($entry->amount)->toBe('2000.00')
        ->and($entry->paid_on->toDateString())->toBe('2026-02-20')
        ->and($entry->method)->toBe(PaymentMethod::BankTransfer)
        ->and($entry->reference)->toBe('VIR-123')
        ->and($entry->recorded_by_id)->toBe($this->user->id)
        ->and($entry->organization_id)->toBe($this->org->id);
});

test('the balance completes the invoice on the date of the last payment', function () {
    ($this->record)(InvoicePaymentKind::Payment, '2000.00', '2026-02-20');
    ($this->record)(InvoicePaymentKind::Payment, '3600.00', '2026-02-21');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($this->invoice->amount_paid)->toBe('5600.00')
        ->and($this->invoice->paid_at->toDateString())->toBe('2026-02-21');
});

test('amounts are added in cents so decimals never drift', function () {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->invoice->contact_id,
        'amount' => '0.30',
        'amount_paid' => 0,
        'status' => InvoiceStatus::Sent,
    ]);

    foreach (range(1, 3) as $ignored) {
        ($this->record)(InvoicePaymentKind::Payment, '0.10', '2026-02-21', $invoice);
    }

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_paid)->toBe('0.30');
});

test('a balance that floating point would put one cent short can still be paid', function () {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->invoice->contact_id,
        'amount' => '0.29',
        'amount_paid' => 0,
        'status' => InvoiceStatus::Sent,
    ]);

    ($this->record)(InvoicePaymentKind::Payment, '0.01', '2026-02-21', $invoice);
    ($this->record)(InvoicePaymentKind::Payment, '0.28', '2026-02-21', $invoice);

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->amount_paid)->toBe('0.29');
});

test('a payment above the balance is refused and nothing is written', function () {
    ($this->record)(InvoicePaymentKind::Payment, '2000.00', '2026-02-20');

    expect(fn () => ($this->record)(InvoicePaymentKind::Payment, '3600.01'))
        ->toThrow(InvoicePaymentRefused::class, "The payment can't be more than the balance due (\$3,600.00).");

    expect($this->invoice->payments()->count())->toBe(1)
        ->and($this->invoice->refresh()->amount_paid)->toBe('2000.00');
});

test('a zero or negative amount is refused', function (string $amount) {
    expect(fn () => ($this->record)(InvoicePaymentKind::Payment, $amount))->toThrow(InvoicePaymentRefused::class);

    expect($this->invoice->payments()->count())->toBe(0);
})->with(['0.00', '-5.00']);

test('only a sent, partial or overdue invoice accepts a payment', function (InvoiceStatus $status, bool $accepted) {
    $this->invoice->update(['status' => $status]);

    if ($accepted) {
        ($this->record)(InvoicePaymentKind::Payment, '100.00');
        expect($this->invoice->payments()->count())->toBe(1);

        return;
    }

    expect(fn () => ($this->record)(InvoicePaymentKind::Payment, '100.00'))
        ->toThrow(InvoicePaymentRefused::class, 'This invoice no longer accepts payments.');
    expect($this->invoice->payments()->count())->toBe(0);
})->with([
    'draft' => [InvoiceStatus::Draft, false],
    'sent' => [InvoiceStatus::Sent, true],
    'overdue' => [InvoiceStatus::Overdue, true],
    'cancelled' => [InvoiceStatus::Cancelled, false],
]);

test('an overdue invoice becomes partial after a part payment', function () {
    $this->invoice->update(['status' => InvoiceStatus::Overdue]);

    ($this->record)(InvoicePaymentKind::Payment, '100.00');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Partial);
});

test('a partial refund on a paid invoice makes it partial and keeps the paid date', function () {
    ($this->record)(InvoicePaymentKind::Payment, '2000.00', '2026-02-20');
    ($this->record)(InvoicePaymentKind::Payment, '3600.00', '2026-02-21');

    ($this->record)(InvoicePaymentKind::Refund, '1000.00', '2026-02-21');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Partial)
        ->and($this->invoice->amount_paid)->toBe('4600.00')
        ->and($this->invoice->paid_at->toDateString())->toBe('2026-02-21');
});

test('a refund keeps the paid date of the last payment even when it is dated later', function () {
    ($this->record)(InvoicePaymentKind::Payment, '2000.00', '2026-02-10');

    ($this->record)(InvoicePaymentKind::Refund, '500.00', '2026-02-21');

    expect($this->invoice->refresh()->paid_at->toDateString())->toBe('2026-02-10');
});

test('a refund above the amount paid is refused', function () {
    ($this->record)(InvoicePaymentKind::Payment, '4600.00');

    expect(fn () => ($this->record)(InvoicePaymentKind::Refund, '4600.01'))
        ->toThrow(InvoicePaymentRefused::class, "The refund can't be more than the amount paid (\$4,600.00).");

    expect($this->invoice->payments()->count())->toBe(1);
});

test('there is nothing to refund on an unpaid invoice', function () {
    expect(fn () => ($this->record)(InvoicePaymentKind::Refund, '1.00'))
        ->toThrow(InvoicePaymentRefused::class, 'There is nothing to refund on this invoice.');
});

test('a full refund brings the invoice back to sent without a paid date', function () {
    ($this->record)(InvoicePaymentKind::Payment, '2000.00');

    ($this->record)(InvoicePaymentKind::Refund, '2000.00');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Sent)
        ->and($this->invoice->amount_paid)->toBe('0.00')
        ->and($this->invoice->paid_at)->toBeNull();
});

test('a refund on a cancelled invoice keeps it cancelled', function () {
    ($this->record)(InvoicePaymentKind::Payment, '2000.00');
    $this->invoice->update(['status' => InvoiceStatus::Cancelled]);

    ($this->record)(InvoicePaymentKind::Refund, '2000.00');

    expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
        ->and($this->invoice->amount_paid)->toBe('0.00')
        ->and($this->invoice->paid_at)->toBeNull();
});

test('the service refuses under lock what a stale caller still believed', function () {
    ($this->record)(InvoicePaymentKind::Payment, '2000.00');
    $stale = Invoice::query()->findOrFail($this->invoice->id);

    ($this->record)(InvoicePaymentKind::Payment, '3600.00');

    expect(fn () => ($this->record)(InvoicePaymentKind::Payment, '3600.00', '2026-02-21', $stale))
        ->toThrow(InvoicePaymentRefused::class, 'This invoice no longer accepts payments.');

    expect($this->invoice->refresh()->amount_paid)->toBe('5600.00')
        ->and($this->invoice->payments()->count())->toBe(2);
});

test('the service locks the invoice row', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    ($this->record)(InvoicePaymentKind::Payment, '100.00');

    expect(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, 'from "invoices"') && str_contains($sql, 'for update')))->toBeTrue();
});

test('ledger entries are append-only', function () {
    $entry = ($this->record)(InvoicePaymentKind::Payment, '100.00');

    expect(fn () => $entry->update(['amount' => '1.00']))
        ->toThrow(LogicException::class, 'Invoice payments are append-only: record a refund instead.');
    expect(fn () => $entry->delete())
        ->toThrow(LogicException::class, 'Invoice payments are append-only: record a refund instead.');

    expect($entry->fresh()->amount)->toBe('100.00');
});

test('the ledger of another organization is not reachable', function () {
    $other = Organization::factory()->create();
    $foreign = Invoice::factory()->create([
        'organization_id' => $other->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $other->id])->id,
        'amount' => '500.00',
        'amount_paid' => 0,
        'status' => InvoiceStatus::Sent,
    ]);
    $row = InvoicePayment::factory()->create(['invoice_id' => $foreign->id]);
    ($this->record)(InvoicePaymentKind::Payment, '100.00');

    expect($row->organization_id)->toBe($other->id)
        ->and(InvoicePayment::query()->count())->toBe(1)
        ->and(InvoicePayment::query()->whereKey($row->id)->exists())->toBeFalse();

    expect(fn () => ($this->record)(InvoicePaymentKind::Payment, '50.00', '2026-02-21', $foreign))
        ->toThrow(ModelNotFoundException::class);
    expect(InvoicePayment::query()->forOrganization($other->id)->count())->toBe(1);
});

test('an invoice with payments cannot be deleted by the policy', function () {
    $free = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->invoice->contact_id,
        'status' => InvoiceStatus::Draft,
    ]);
    ($this->record)(InvoicePaymentKind::Payment, '100.00');

    expect($this->user->can('delete', $this->invoice))->toBeFalse()
        ->and($this->user->can('delete', $free))->toBeTrue();
});
