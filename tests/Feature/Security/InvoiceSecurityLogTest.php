<?php

use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Services\Invoices\InvoiceLedger;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function invoiceLogContext(object $test, User $user, array $extra): array
{
    return [...$extra, 'user_id' => $user->id, 'organization_id' => $test->org->id, 'ip' => '127.0.0.1'];
}

function invoiceLogContexts(TestHandler $log): string
{
    return json_encode(array_column(securityLogEntries($log), 'context'), JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 12:00:00');

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->makeInvoice = fn (string $number, InvoiceStatus $status = InvoiceStatus::Sent): Invoice => Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'invoice_number' => $number,
        'amount' => '5600.00',
        'amount_paid' => 0,
        'status' => $status,
        'due_at' => '2026-02-26',
        'paid_at' => null,
    ]);

    $this->invoice = ($this->makeInvoice)('INV-2026-001');

    $this->ledger = fn (InvoicePaymentKind $kind, string $amount, ?Invoice $invoice = null): InvoicePayment => app(InvoiceLedger::class)->record(
        $invoice ?? $this->invoice, $kind, $amount, '2026-02-20', null, null, $this->owner,
    );

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };

    $this->forged = fn ($page, string $action) => $page
        ->call('mountAction', $action, [], [])
        ->assertActionNotMounted()
        ->call('callMountedAction');
});

afterEach(function () {
    Carbon::setTestNow();
});

describe('refunds', function () {
    it('writes one warning line when an admin records a refund', function () {
        ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
        $ada = ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
            ->callAction('recordRefund', data: ['amount' => '500.00', 'paid_on' => '2026-02-21'])
            ->assertHasNoActionErrors()
            ->assertNotified('Refund recorded');

        $refund = InvoicePayment::query()->where('kind', InvoicePaymentKind::Refund)->sole();

        expect(securityLogEntries($log))->toBe([[
            'event' => 'invoice.refund_recorded',
            'level' => 'warning',
            'context' => invoiceLogContext($this, $ada, ['invoice_id' => $this->invoice->id, 'invoice_payment_id' => $refund->id]),
        ]]);
    });

    it('writes no line for a payment', function () {
        $log = fakeSecurityLog();

        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
            ->callAction('recordPayment', data: ['amount' => '100.00', 'paid_on' => '2026-02-21'])
            ->assertNotified('Payment recorded');

        expect(securityLogEntries($log))->toBe([])
            ->and($this->invoice->payments()->count())->toBe(1);
    });

    it('writes no line for a refund over the amount paid', function () {
        ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
        $log = fakeSecurityLog();

        $page = Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
            ->mountAction('recordRefund')
            ->setActionData(['amount' => '2000.01', 'paid_on' => '2026-02-21'])
            ->callMountedAction()
            ->assertHasActionErrors(['amount']);

        expect($page->errors()->get('mountedActions.0.data.amount'))->toContain("The refund can't be more than the amount paid (\$2,000.00).")
            ->and(securityLogEntries($log))->toBe([])
            ->and($this->invoice->payments()->count())->toBe(1);
    });

    it('refuses a member who forges the refund action and writes no line', function () {
        ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
        ($this->actAs)(OrganizationRole::Member);
        $log = fakeSecurityLog();

        ($this->forged)(Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id]), 'recordRefund');

        expect(securityLogEntries($log))->toBe([])
            ->and($this->invoice->payments()->count())->toBe(1);
    });
});

describe('cancellations', function () {
    it('writes one warning line when an admin cancels from the view page', function () {
        $ada = ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])->callAction('cancelInvoice');

        expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and(securityLogEntries($log))->toBe([[
                'event' => 'invoice.cancelled',
                'level' => 'warning',
                'context' => invoiceLogContext($this, $ada, ['invoice_id' => $this->invoice->id, 'from_status' => 'sent', 'source' => 'action']),
            ]]);
    });

    it('writes no line when the cancel action is forged on a paid invoice', function () {
        ($this->ledger)(InvoicePaymentKind::Payment, '5600.00');
        ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        ($this->forged)(Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id]), 'cancelInvoice');

        expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Paid)
            ->and(securityLogEntries($log))->toBe([]);
    });

    it('writes no line when the cancel action is forged on a cancelled invoice', function () {
        $this->invoice->update(['status' => InvoiceStatus::Cancelled]);
        ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        ($this->forged)(Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id]), 'cancelInvoice');

        expect(securityLogEntries($log))->toBe([]);
    });

    it('refuses a member who forges the cancel action and writes no line', function () {
        ($this->actAs)(OrganizationRole::Member);
        $log = fakeSecurityLog();

        ($this->forged)(Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id]), 'cancelInvoice');

        expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Sent)
            ->and(securityLogEntries($log))->toBe([]);
    });

    it('writes one warning line when an admin cancels through the edit form', function () {
        $ada = ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        Livewire::test(EditInvoice::class, ['record' => $this->invoice->id])
            ->fillForm(['status' => InvoiceStatus::Cancelled->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and(securityLogEntries($log))->toBe([[
                'event' => 'invoice.cancelled',
                'level' => 'warning',
                'context' => invoiceLogContext($this, $ada, ['invoice_id' => $this->invoice->id, 'from_status' => 'sent', 'source' => 'edit_form']),
            ]]);
    });

    it('refuses a member who forges a cancellation through the edit form and writes no line', function (InvoiceStatus $from) {
        $this->invoice->update(['status' => $from]);
        ($this->actAs)(OrganizationRole::Member);
        $log = fakeSecurityLog();

        $page = Livewire::test(EditInvoice::class, ['record' => $this->invoice->id])
            ->fillForm(['status' => InvoiceStatus::Cancelled->value])
            ->call('save')
            ->assertHasFormErrors(['status' => 'in']);

        expect($page->errors()->get('data.status'))->toBe(['Only the owner, an admin or the person who created this invoice can change its status.'])
            ->and($this->invoice->refresh()->status)->toBe($from)
            ->and(securityLogEntries($log))->toBe([]);
    })->with([InvoiceStatus::Sent, InvoiceStatus::Draft, InvoiceStatus::Overdue]);

    it('offers every status to admins and the owner and only the stored one to another member', function (?OrganizationRole $role, array $options) {
        if ($role !== null) {
            ($this->actAs)($role);
        }

        $page = Livewire::test(EditInvoice::class, ['record' => $this->invoice->id]);

        expect(array_keys($page->instance()->getSchema('form')->getComponent('status')->getOptions()))->toBe($options);
    })->with([
        'member' => [OrganizationRole::Member, ['sent']],
        'admin' => [OrganizationRole::Admin, ['draft', 'sent', 'overdue', 'cancelled']],
        'owner' => [null, ['draft', 'sent', 'overdue', 'cancelled']],
    ]);

    it('has no status select on the create page, so nothing can start cancelled', function () {
        $page = Livewire::test(CreateInvoice::class);

        expect($page->instance()->getSchema('form')->getComponent('status'))->toBeNull();
    });

    it('refuses an admin demoted to member while the edit page is open', function () {
        $ada = ($this->actAs)(OrganizationRole::Admin);

        $page = Livewire::test(EditInvoice::class, ['record' => $this->invoice->id]);

        $this->org->members()->updateExistingPivot($ada->id, ['role' => OrganizationRole::Member->value]);
        $this->actingAs($ada->fresh());
        $log = fakeSecurityLog();

        $page->fillForm(['status' => InvoiceStatus::Cancelled->value])
            ->call('save')
            ->assertHasFormErrors(['status' => 'in']);

        expect($page->errors()->get('data.status'))->toBe(['Only the owner, an admin or the person who created this invoice can change its status.'])
            ->and($this->invoice->refresh()->status)->toBe(InvoiceStatus::Sent)
            ->and(securityLogEntries($log))->toBe([]);
    });

    it('lets a member save another field of an already cancelled invoice', function () {
        $this->invoice->update(['status' => InvoiceStatus::Cancelled]);
        ($this->actAs)(OrganizationRole::Member);
        $log = fakeSecurityLog();

        $page = Livewire::test(EditInvoice::class, ['record' => $this->invoice->id]);

        expect(array_keys($page->instance()->getSchema('form')->getComponent('status')->getOptions()))->toContain('cancelled');

        $page->fillForm(['notes' => 'Called the client'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and($this->invoice->notes)->toBe('Called the client')
            ->and(securityLogEntries($log))->toBe([]);
    });

    it('writes no line when an already cancelled invoice is saved again', function () {
        $this->invoice->update(['status' => InvoiceStatus::Cancelled]);
        ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        Livewire::test(EditInvoice::class, ['record' => $this->invoice->id])
            ->fillForm(['notes' => 'Called the client'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->invoice->refresh()->notes)->toBe('Called the client')
            ->and(securityLogEntries($log))->toBe([]);
    });

    it('writes no line for another status change', function () {
        $draft = ($this->makeInvoice)('INV-2026-002', InvoiceStatus::Draft);
        ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        Livewire::test(EditInvoice::class, ['record' => $draft->id])
            ->fillForm(['status' => InvoiceStatus::Sent->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($draft->refresh()->status)->toBe(InvoiceStatus::Sent)
            ->and(securityLogEntries($log))->toBe([]);
    });

    it('keeps no stale status between two saves of the same page', function () {
        ($this->actAs)(OrganizationRole::Admin);

        $page = Livewire::test(EditInvoice::class, ['record' => $this->invoice->id])
            ->fillForm(['status' => InvoiceStatus::Cancelled->value])
            ->call('save');

        $log = fakeSecurityLog();

        $page->fillForm(['notes' => 'Second save'])->call('save')->assertHasNoFormErrors();

        expect(securityLogEntries($log))->toBe([]);
    });
});

describe('deletions', function () {
    it('writes one warning line when an admin deletes a draft from its edit page', function () {
        $draft = ($this->makeInvoice)('INV-2026-002', InvoiceStatus::Draft);
        $ada = ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        Livewire::test(EditInvoice::class, ['record' => $draft->id])
            ->callAction(DeleteAction::class)
            ->assertNotified()
            ->assertRedirect();

        expect(Invoice::query()->whereKey($draft->id)->exists())->toBeFalse()
            ->and(securityLogEntries($log))->toBe([[
                'event' => 'invoice.deleted',
                'level' => 'warning',
                'context' => invoiceLogContext($this, $ada, ['invoice_id' => $draft->id, 'status' => 'draft']),
            ]]);
    });

    it('logs only the invoices a bulk delete really removed', function () {
        $draft = ($this->makeInvoice)('INV-2026-002', InvoiceStatus::Draft);
        $paid = ($this->makeInvoice)('INV-2026-003');
        ($this->ledger)(InvoicePaymentKind::Payment, '100.00', $paid);
        $ada = ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        Livewire::test(ListInvoices::class)
            ->selectTableRecords([$draft->id, $paid->id])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

        expect(Invoice::query()->whereKey($draft->id)->exists())->toBeFalse()
            ->and(Invoice::query()->whereKey($paid->id)->exists())->toBeTrue()
            ->and(securityLogEntries($log))->toBe([[
                'event' => 'invoice.deleted',
                'level' => 'warning',
                'context' => invoiceLogContext($this, $ada, ['invoice_id' => $draft->id, 'status' => 'draft']),
            ]]);
    });

    it('writes one line per invoice of a bulk delete', function () {
        $first = ($this->makeInvoice)('INV-2026-002', InvoiceStatus::Draft);
        $second = ($this->makeInvoice)('INV-2026-003', InvoiceStatus::Sent);
        ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        Livewire::test(ListInvoices::class)
            ->selectTableRecords([$first->id, $second->id])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

        $ids = array_column(array_column(securityLogEntries($log), 'context'), 'invoice_id');
        sort($ids);

        expect($ids)->toBe([$first->id, $second->id]);
    });

    it('writes no line when a member forges a delete', function () {
        $draft = ($this->makeInvoice)('INV-2026-002', InvoiceStatus::Draft);
        ($this->actAs)(OrganizationRole::Member);
        $log = fakeSecurityLog();

        Livewire::test(ListInvoices::class)
            ->selectTableRecords([$draft->id])
            ->call('mountAction', 'delete', [], ['table' => true, 'bulk' => true])
            ->call('callMountedAction');

        expect(Invoice::query()->whereKey($draft->id)->exists())->toBeTrue()
            ->and(securityLogEntries($log))->toBe([]);
    });

    it('writes no line for a delete that returns false', function () {
        $target = $this->invoice;
        Invoice::deleting(fn (Invoice $invoice): ?bool => $invoice->is($target) ? false : null);
        ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        expect(InvoicesTable::deleteInvoice($target))->toBeFalse()
            ->and(Invoice::query()->whereKey($target->id)->exists())->toBeTrue()
            ->and(securityLogEntries($log))->toBe([]);
    });

    it('keeps no amount, number or contact data in any line', function () {
        ($this->ledger)(InvoicePaymentKind::Payment, '2000.00');
        ($this->actAs)(OrganizationRole::Admin);
        $log = fakeSecurityLog();

        Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
            ->callAction('recordRefund', data: ['amount' => '500.00', 'paid_on' => '2026-02-21', 'reference' => 'SECRET-REF'])
            ->callAction('cancelInvoice');

        $lines = invoiceLogContexts($log);

        expect(securityLogEntries($log))->toHaveCount(2)
            ->and($lines)->not->toContain('SECRET-REF')
            ->and($lines)->not->toContain('INV-2026-001')
            ->and($lines)->not->toContain('500')
            ->and($lines)->not->toContain('5600');
    });
});
