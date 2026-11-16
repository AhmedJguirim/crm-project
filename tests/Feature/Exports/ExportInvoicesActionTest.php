<?php

use App\Enums\ExportFormat;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Jobs\ExportInvoicesJob;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Queue::fake();

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->org->update(['timezone' => 'Pacific/Auckland']);
    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };

    $this->invoice = fn (string $number, string $dueAt, InvoiceStatus $status = InvoiceStatus::Sent, ?int $organizationId = null): Invoice => Invoice::factory()->create([
        'organization_id' => $organizationId ?? $this->org->id,
        'contact_id' => $organizationId === null ? $this->contact->id : Contact::factory()->create(['organization_id' => $organizationId])->id,
        'created_by' => $this->owner->id,
        'invoice_number' => $number,
        'status' => $status,
        'amount' => '100.00',
        'amount_paid' => $status === InvoiceStatus::Paid ? '100.00' : '0.00',
        'issued_at' => '2026-10-01',
        'due_at' => $dueAt,
        'paid_at' => $status === InvoiceStatus::Paid ? '2026-11-01' : null,
    ]);

    $this->alpha = ($this->invoice)('INV-ALPHA', '2026-12-03');
    $this->bravo = ($this->invoice)('INV-BRAVO', '2026-12-01', InvoiceStatus::Paid);
    $this->charlie = ($this->invoice)('INV-CHARLIE', '2026-12-02');

    $this->pushedJob = function (): array {
        return Queue::pushed(ExportInvoicesJob::class)
            ->map(fn (ExportInvoicesJob $job): array => (fn (): array => [
                'ids' => $this->invoiceIds,
                'organization' => $this->organizationId,
                'user' => $this->userId,
                'format' => $this->format,
                'name' => $this->downloadName,
            ])->call($job))
            ->values()
            ->all();
    };
});

describe('an admin', function () {
    beforeEach(function () {
        $this->admin = ($this->actAs)(OrganizationRole::Admin);
    });

    it('exports what the list shows, in the order of the list, as an Excel file by default', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 22:30:00', 'UTC'));

        Livewire::test(ListInvoices::class)
            ->callAction('exportInvoices', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toBe([$this->bravo->id, $this->charlie->id, $this->alpha->id])
            ->and($jobs[0]['organization'])->toBe($this->org->id)
            ->and($jobs[0]['user'])->toBe($this->admin->id)
            ->and($jobs[0]['format'])->toBe('xlsx')
            ->and($jobs[0]['name'])->toBe('invoices-2026-10-07.xlsx');
    });

    it('names a CSV export a ZIP, with the local date of the organization', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 22:30:00', 'UTC'));

        Livewire::test(ListInvoices::class)->callAction('exportInvoices', ['format' => ExportFormat::Csv->value]);

        $jobs = ($this->pushedJob)();

        expect($jobs[0]['format'])->toBe('csv')
            ->and($jobs[0]['name'])->toBe('invoices-2026-10-07.zip');
    });

    it('follows the filters', function () {
        Livewire::test(ListInvoices::class)
            ->filterTable('status', InvoiceStatus::Paid->value)
            ->callAction('exportInvoices', ['format' => ExportFormat::Csv->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->bravo->id]);
    });

    it('follows the sort and the search', function () {
        Livewire::test(ListInvoices::class)
            ->sortTable('due_at', 'desc')
            ->callAction('exportInvoices', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->alpha->id, $this->charlie->id, $this->bravo->id]);

        Queue::fake();

        Livewire::test(ListInvoices::class)
            ->searchTable('INV-CHARLIE')
            ->callAction('exportInvoices', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->charlie->id]);
    });

    it('exports all pages, not only the one shown', function () {
        Invoice::factory()->count(30)->create(['organization_id' => $this->org->id, 'contact_id' => $this->contact->id, 'created_by' => $this->owner->id]);

        Livewire::test(ListInvoices::class)->callAction('exportInvoices', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toHaveCount(33);
    });

    it('exports only the invoices of the organization in use', function () {
        $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
        ($this->invoice)('INV-GLOBEX', '2026-12-05', InvoiceStatus::Sent, $other->id);

        Livewire::test(ListInvoices::class)->callAction('exportInvoices', ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['ids'])->toHaveCount(3);
    });

    it('defaults to Excel and refuses another format', function () {
        Livewire::test(ListInvoices::class)
            ->mountAction('exportInvoices')
            ->assertSchemaStateSet(['format' => ExportFormat::Xlsx])
            ->setActionData(['format' => 'pdf'])
            ->callMountedAction()
            ->assertHasActionErrors(['format']);

        Queue::assertNotPushed(ExportInvoicesJob::class);
    });

    it('warns when there is nothing to export', function () {
        Livewire::test(ListInvoices::class)
            ->searchTable('nobody matches this')
            ->callAction('exportInvoices', ['format' => ExportFormat::Xlsx->value])
            ->assertNotified('Nothing to export.');

        Queue::assertNotPushed(ExportInvoicesJob::class);
    });

    it('tells that a CSV comes as a ZIP of two files', function () {
        Livewire::test(ListInvoices::class)
            ->mountAction('exportInvoices')
            ->assertSchemaComponentExists('format', 'mountedActionSchema0', function (Select $field): bool {
                $helperText = collect($field->getChildComponents($field::BELOW_CONTENT_SCHEMA_KEY))->map(fn ($component): string => (string) $component->getContent())->implode(' ');

                return str_contains($helperText, 'CSV comes as a ZIP of invoices.csv and payments.csv')
                    && str_contains($helperText, 'Excel has an Invoices sheet and a Payments sheet.');
            });
    });

    it('exports the selected invoices as a csv with a zip name, whatever the sort', function () {
        Livewire::test(ListInvoices::class)
            ->sortTable('due_at', 'desc')
            ->callTableBulkAction('exportSelected', [$this->alpha, $this->charlie], ['format' => ExportFormat::Csv->value])
            ->assertNotified('Export queued');

        $jobs = ($this->pushedJob)();

        expect($jobs)->toHaveCount(1)
            ->and($jobs[0]['ids'])->toEqualCanonicalizing([$this->alpha->id, $this->charlie->id])
            ->and($jobs[0]['format'])->toBe('csv')
            ->and($jobs[0]['name'])->toEndWith('.zip')
            ->and($jobs[0]['user'])->toBe($this->admin->id);
    });

    it('names an Excel selection export .xlsx', function () {
        Livewire::test(ListInvoices::class)
            ->callTableBulkAction('exportSelected', [$this->alpha], ['format' => ExportFormat::Xlsx->value]);

        expect(($this->pushedJob)()[0]['name'])->toEndWith('.xlsx');
    });

    it('exports nothing of another organization when the selection is forged', function () {
        $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
        $foreign = ($this->invoice)('INV-GLOBEX', '2026-12-05', InvoiceStatus::Sent, $other->id);

        Livewire::test(ListInvoices::class)
            ->set('isTrackingDeselectedTableRecords', false)
            ->set('selectedTableRecords', [(string) $this->alpha->id, (string) $foreign->id])
            ->set('deselectedTableRecords', [])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->fillForm(['format' => ExportFormat::Csv->value])
            ->callMountedAction();

        expect(($this->pushedJob)()[0]['ids'])->toBe([$this->alpha->id]);
    });

    it('writes a file with only the selected invoices and their payments', function () {
        Storage::fake('local');
        InvoicePayment::factory()->create(['organization_id' => $this->org->id, 'invoice_id' => $this->alpha->id, 'reference' => 'alpha-payment']);
        InvoicePayment::factory()->create(['organization_id' => $this->org->id, 'invoice_id' => $this->bravo->id, 'reference' => 'bravo-payment']);

        Livewire::test(ListInvoices::class)
            ->set('isTrackingDeselectedTableRecords', false)
            ->set('selectedTableRecords', [(string) $this->alpha->id, (string) $this->charlie->id])
            ->set('deselectedTableRecords', [])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->fillForm(['format' => ExportFormat::Xlsx->value])
            ->callMountedAction();

        Queue::pushed(ExportInvoicesJob::class)->sole()->handle();

        $path = Storage::disk('local')->path(Storage::disk('local')->files('exports')[0]);
        $invoices = SimpleExcelReader::create($path, 'xlsx')->fromSheetName('Invoices')->getRows()->all();
        $payments = SimpleExcelReader::create($path, 'xlsx')->fromSheetName('Payments')->getRows()->all();

        expect(array_column($invoices, 'number'))->toEqualCanonicalizing(['INV-ALPHA', 'INV-CHARLIE'])
            ->and(array_column($payments, 'reference'))->toBe(['alpha-payment']);
    });
});

describe('the owner', function () {
    it('exports too', function () {
        $this->actingAs($this->owner);
        Filament::setTenant($this->org);

        Livewire::test(ListInvoices::class)
            ->assertActionVisible('exportInvoices')
            ->assertTableBulkActionVisible('exportSelected')
            ->callAction('exportInvoices', ['format' => ExportFormat::Xlsx->value]);

        Queue::assertPushed(ExportInvoicesJob::class, 1);
    });
});

describe('a member or a viewer', function () {
    it('sees neither export action', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListInvoices::class)
            ->assertActionHidden('exportInvoices')
            ->assertTableBulkActionHidden('exportSelected');
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);

    it('is refused when calling the actions anyway', function (OrganizationRole $role) {
        ($this->actAs)($role);

        Livewire::test(ListInvoices::class)
            ->call('mountAction', 'exportInvoices', [], [])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Livewire::test(ListInvoices::class)
            ->set('selectedTableRecords', [$this->alpha->getKey()])
            ->call('mountAction', 'exportSelected', [], ['table' => true, 'bulk' => true])
            ->assertActionNotMounted()
            ->call('callMountedAction');

        Queue::assertNotPushed(ExportInvoicesJob::class);
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);
});
