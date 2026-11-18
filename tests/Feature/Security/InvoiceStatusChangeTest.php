<?php

use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

const INVOICE_STATUS_REFUSAL = 'Only the owner, an admin or the person who created this invoice can change its status.';

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 12:00:00');

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();

    $this->member = function (OrganizationRole $role, string $name): User {
        $user = User::factory()->onboardingCompleted()->create(['name' => $name]);
        $this->org->members()->attach($user, ['role' => $role->value]);

        return $user;
    };

    $this->carl = ($this->member)(OrganizationRole::Member, 'Carl');
    $this->mia = ($this->member)(OrganizationRole::Member, 'Mia');
    $this->ada = ($this->member)(OrganizationRole::Admin, 'Ada');
    $this->vic = ($this->member)(OrganizationRole::Viewer, 'Vic');

    $this->makeInvoice = fn (string $number, InvoiceStatus $status): Invoice => Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'created_by' => $this->carl->id,
        'invoice_number' => $number,
        'amount' => '5600.00',
        'amount_paid' => 0,
        'status' => $status,
        'due_at' => '2026-02-26',
        'paid_at' => null,
    ]);

    $this->as = function (User $user): void {
        $this->actingAs($user);
        Filament::setTenant($this->org);
    };

    $this->forged = fn ($page, string $action) => $page
        ->call('mountAction', $action, [], [])
        ->assertActionNotMounted()
        ->call('callMountedAction');

    $this->statusOptions = fn ($page): array => array_keys($page->instance()->getSchema('form')->getComponent('status')->getOptions());
});

describe('view page actions', function () {
    it('shows send and cancel to the owner, admins and the creator only', function (string $who, bool $visible) {
        $draft = ($this->makeInvoice)('INV-2026-001', InvoiceStatus::Draft);
        $sent = ($this->makeInvoice)('INV-2026-002', InvoiceStatus::Sent);
        ($this->as)($who === 'owner' ? $this->owner : $this->{$who});

        $send = Livewire::test(ViewInvoice::class, ['record' => $draft->id]);
        $cancel = Livewire::test(ViewInvoice::class, ['record' => $sent->id]);

        if ($visible) {
            $send->assertActionVisible('markAsSent');
            $cancel->assertActionVisible('cancelInvoice');

            return;
        }

        $send->assertActionHidden('markAsSent');
        $cancel->assertActionHidden('cancelInvoice');
    })->with([
        'owner' => ['owner', true],
        'admin' => ['ada', true],
        'creator' => ['carl', true],
        'other member' => ['mia', false],
        'viewer' => ['vic', false],
    ]);

    it('lets the creator send then cancel an invoice and logs the cancellation', function () {
        $invoice = ($this->makeInvoice)('INV-2026-001', InvoiceStatus::Draft);
        ($this->as)($this->carl);
        $log = fakeSecurityLog();

        $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->id]);
        $page->callAction('markAsSent');

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent);

        $page->callAction('cancelInvoice');

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Cancelled)
            ->and(securityLogEntries($log))->toBe([[
                'event' => 'invoice.cancelled',
                'level' => 'warning',
                'context' => ['invoice_id' => $invoice->id, 'from_status' => 'sent', 'source' => 'action', 'user_id' => $this->carl->id, 'organization_id' => $this->org->id, 'ip' => '127.0.0.1'],
            ]]);
    });

    it('refuses a non-creator member who forges a status action and writes nothing', function (string $action, InvoiceStatus $from) {
        $invoice = ($this->makeInvoice)('INV-2026-001', $from);
        ($this->as)($this->mia);
        $log = fakeSecurityLog();

        ($this->forged)(Livewire::test(ViewInvoice::class, ['record' => $invoice->id]), $action);

        expect($invoice->refresh()->status)->toBe($from)
            ->and(securityLogEntries($log))->toBe([]);
    })->with([
        'markAsSent' => ['markAsSent', InvoiceStatus::Draft],
        'cancelInvoice' => ['cancelInvoice', InvoiceStatus::Sent],
    ]);

    it('refuses the creator after a demotion to viewer who forges a status action', function () {
        $invoice = ($this->makeInvoice)('INV-2026-001', InvoiceStatus::Draft);
        ($this->as)($this->carl);
        $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->id]);

        $this->org->members()->updateExistingPivot($this->carl->id, ['role' => OrganizationRole::Viewer->value]);
        ($this->as)($this->carl->fresh());
        $log = fakeSecurityLog();

        ($this->forged)($page, 'markAsSent');

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft)
            ->and(securityLogEntries($log))->toBe([]);
    });

    it('does not let a creator of another organization invoice change its status', function () {
        $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $invoice = ($this->makeInvoice)('INV-2026-001', InvoiceStatus::Draft);
        $invoice->forceFill(['created_by' => $other->id])->saveQuietly();
        $this->actingAs($other);
        Filament::setTenant($other->personalOrganization());

        expect($other->can('changeStatus', $invoice))->toBeFalse();
    });
});

describe('edit form', function () {
    it('offers every status to the owner and the creator and only the stored one to another member', function (string $who, array $options, string $helper) {
        $invoice = ($this->makeInvoice)('INV-2026-001', InvoiceStatus::Sent);
        ($this->as)($who === 'owner' ? $this->owner : $this->{$who});

        $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id]);
        $field = $page->instance()->getSchema('form')->getComponent('status');

        expect(($this->statusOptions)($page))->toBe($options)
            ->and(collect($field->getChildSchema($field::BELOW_CONTENT_SCHEMA_KEY)?->getComponents() ?? [])->map(fn ($component): string => (string) $component->getContent())->implode(' '))->toBe($helper);
    })->with([
        'owner' => ['owner', ['draft', 'sent', 'overdue', 'cancelled'], ''],
        'creator' => ['carl', ['draft', 'sent', 'overdue', 'cancelled'], ''],
        'other member' => ['mia', ['sent'], INVOICE_STATUS_REFUSAL],
    ]);

    it('lets the creator reopen a cancelled invoice', function () {
        $invoice = ($this->makeInvoice)('INV-2026-001', InvoiceStatus::Cancelled);
        ($this->as)($this->carl);

        Livewire::test(EditInvoice::class, ['record' => $invoice->id])
            ->fillForm(['status' => InvoiceStatus::Sent->value])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent);
    });

    it('refuses a non-creator member who forges a status and writes nothing', function (InvoiceStatus $from, InvoiceStatus $to) {
        $invoice = ($this->makeInvoice)('INV-2026-001', $from);
        ($this->as)($this->mia);
        $log = fakeSecurityLog();

        $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id])
            ->fillForm(['status' => $to->value])
            ->call('save')
            ->assertHasFormErrors(['status' => 'in']);

        expect($page->errors()->get('data.status'))->toBe([INVOICE_STATUS_REFUSAL])
            ->and($invoice->refresh()->status)->toBe($from)
            ->and(securityLogEntries($log))->toBe([]);
    })->with([
        'send a draft' => [InvoiceStatus::Draft, InvoiceStatus::Sent],
        'overdue' => [InvoiceStatus::Sent, InvoiceStatus::Overdue],
        'cancel' => [InvoiceStatus::Sent, InvoiceStatus::Cancelled],
        'reopen to draft' => [InvoiceStatus::Cancelled, InvoiceStatus::Draft],
        'reopen to sent' => [InvoiceStatus::Cancelled, InvoiceStatus::Sent],
    ]);

    it('keeps the Paid and Partial forgery message of the select for a person who may change the status', function () {
        $invoice = ($this->makeInvoice)('INV-2026-001', InvoiceStatus::Sent);
        ($this->as)($this->carl);

        $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id])
            ->fillForm(['status' => InvoiceStatus::Paid->value])
            ->call('save')
            ->assertHasFormErrors(['status' => 'in']);

        expect($page->errors()->get('data.status'))->toHaveCount(1)
            ->and($page->errors()->get('data.status'))->not->toContain(INVOICE_STATUS_REFUSAL)
            ->and($invoice->refresh()->status)->toBe(InvoiceStatus::Sent);
    });

    it('still saves other fields for a non-creator member', function () {
        $invoice = ($this->makeInvoice)('INV-2026-001', InvoiceStatus::Sent);
        ($this->as)($this->mia);

        Livewire::test(EditInvoice::class, ['record' => $invoice->id])
            ->fillForm(['notes' => 'Called the client'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($invoice->refresh()->notes)->toBe('Called the client')
            ->and($invoice->status)->toBe(InvoiceStatus::Sent);
    });

    it('refuses the creator demoted to viewer while the edit page is open', function () {
        $invoice = ($this->makeInvoice)('INV-2026-001', InvoiceStatus::Sent);
        ($this->as)($this->carl);
        $page = Livewire::test(EditInvoice::class, ['record' => $invoice->id]);

        $this->org->members()->updateExistingPivot($this->carl->id, ['role' => OrganizationRole::Viewer->value]);
        ($this->as)($this->carl->fresh());
        $log = fakeSecurityLog();

        try {
            $page->fillForm(['status' => InvoiceStatus::Cancelled->value])->call('save');
        } catch (InvalidArgumentException) {
        }

        expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent)
            ->and(array_filter(securityLogEntries($log), fn (array $entry): bool => str_starts_with($entry['event'], 'invoice.')))->toBe([]);
    });
});
