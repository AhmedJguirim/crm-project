<?php

use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Widgets\OverdueInvoicesWidget;
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

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ingrid Svensson']);

    $this->lateInvoice = fn (string $number, InvoiceStatus $status, float|int $amount, float|int $paid, string $dueAt, array $extra = []): Invoice => Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'invoice_number' => $number,
        'status' => $status,
        'amount' => $amount,
        'amount_paid' => $paid,
        'paid_at' => null,
        'due_at' => $dueAt,
        ...$extra,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('it lists the oldest overdue invoices first, five per page, with balance and days late', function () {
    $a = ($this->lateInvoice)('INV-A', InvoiceStatus::Overdue, 9200, 0, '2026-02-13');
    $b = ($this->lateInvoice)('INV-B', InvoiceStatus::Partial, 5600, 2000, '2026-02-19');
    $c = ($this->lateInvoice)('INV-C', InvoiceStatus::Sent, 100, 0, '2026-02-20');
    $d = ($this->lateInvoice)('INV-D', InvoiceStatus::Sent, 100, 0, '2026-01-01');
    $e = ($this->lateInvoice)('INV-E', InvoiceStatus::Sent, 100, 0, '2026-01-02');
    $f = ($this->lateInvoice)('INV-F', InvoiceStatus::Sent, 100, 0, '2026-02-18');
    $g = ($this->lateInvoice)('INV-G', InvoiceStatus::Sent, 100, 0, '2026-02-21');
    $h = ($this->lateInvoice)('INV-H', InvoiceStatus::Draft, 100, 0, '2025-01-01');

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertCanSeeTableRecords([$d, $e, $a, $f, $b], inOrder: true)
        ->assertCanNotSeeTableRecords([$c, $g, $h])
        ->assertSeeInOrder(['INV-A', 'Ingrid Svensson', '$9,200.00', 'Feb 13, 2026', '8 days'])
        ->assertSeeInOrder(['INV-B', 'Ingrid Svensson', '$3,600.00', 'Feb 19, 2026', '2 days'])
        ->call('nextPage')
        ->assertCanSeeTableRecords([$c]);
});

test('one day late is singular', function () {
    ($this->lateInvoice)('INV-1', InvoiceStatus::Sent, 100, 0, '2026-02-20');

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertSeeInOrder(['INV-1', '1 day'])
        ->assertDontSee('1 days');
});

test('an invoice overdue by status but not by date shows a dash', function () {
    $invoice = ($this->lateInvoice)('INV-2', InvoiceStatus::Overdue, 100, 0, '2026-02-25');

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertCanSeeTableRecords([$invoice])
        ->assertTableColumnStateSet('days_late', '—', $invoice);
});

test('an invoice overdue by status and due today shows a dash, not zero days', function () {
    $invoice = ($this->lateInvoice)('INV-3', InvoiceStatus::Overdue, 100, 0, '2026-02-21');

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertCanSeeTableRecords([$invoice])
        ->assertTableColumnStateSet('days_late', '—', $invoice)
        ->assertDontSee('0 days');
});

test('the invoice links to its page and the header links to the filtered list', function () {
    $invoice = ($this->lateInvoice)('INV-A', InvoiceStatus::Overdue, 9200, 0, '2026-02-13');

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertSeeHtml('href="'.InvoiceResource::getUrl('view', ['record' => $invoice]).'"')
        ->assertActionHasUrl(TestAction::make('seeAll')->table(), InvoiceResource::getUrl('index').'?tableFilters[overdue][isActive]=1');
});

test('the empty state', function () {
    ($this->lateInvoice)('INV-N', InvoiceStatus::Sent, 100, 0, '2026-02-22');

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertSee('No overdue invoices')
        ->assertSee('Every unpaid invoice is within its due date.');
});

test('another organization invoices are not listed', function () {
    $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();

    Invoice::factory()->create([
        'organization_id' => $otherOrg->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $otherOrg->id])->id,
        'invoice_number' => 'INV-OTHER',
        'status' => InvoiceStatus::Overdue,
        'amount' => 100,
        'amount_paid' => 0,
        'due_at' => '2026-01-01',
    ]);

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertDontSee('INV-OTHER');
});

test('an invoice of a soft deleted contact is listed with a dash', function () {
    $invoice = ($this->lateInvoice)('INV-S', InvoiceStatus::Overdue, 300, 0, '2026-02-13');
    $invoice->contact->delete();

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertCanSeeTableRecords([$invoice])
        ->assertSeeInOrder(['INV-S', '—', '$300.00']);
});

test('invoice numbers and contact names are escaped', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => '<b>Evil</b>']);
    ($this->lateInvoice)('<script>alert(1)</script>', InvoiceStatus::Overdue, 100, 0, '2026-02-13', ['contact_id' => $contact->id]);

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertSeeHtml('&lt;b&gt;Evil&lt;/b&gt;')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->assertDontSeeHtml('<b>Evil</b>')
        ->assertDontSeeHtml('<script>alert(1)</script>');
});

test('every role sees the invoice with its view link', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    $invoice = ($this->lateInvoice)('INV-R', InvoiceStatus::Overdue, 400, 0, '2026-02-13');
    $this->actingAs($user);
    Filament::setTenant($this->org);

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$invoice])
        ->assertSeeHtml('href="'.InvoiceResource::getUrl('view', ['record' => $invoice]).'"');
})->with([
    'owner' => OrganizationRole::Owner,
    'admin' => OrganizationRole::Admin,
    'member' => OrganizationRole::Member,
    'viewer' => OrganizationRole::Viewer,
]);

test('the dashboard renders the widget', function () {
    $this->get(Filament::getUrl($this->org))
        ->assertOk()
        ->assertSeeLivewire(OverdueInvoicesWidget::class);
});

test('a forged array table search is turned into an empty search', function () {
    Livewire::test(OverdueInvoicesWidget::class)
        ->set('tableSearch', ['x'])
        ->assertSuccessful()
        ->assertSet('tableSearch', '');
});
