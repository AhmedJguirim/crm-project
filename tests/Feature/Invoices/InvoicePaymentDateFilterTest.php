<?php

use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Services\Owners\OwnerOptions;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Archer']);
    $this->org = $this->user->personalOrganization();
    $this->org->update(['timezone' => 'Europe/Paris']);
    $this->actingAs($this->user);
    Filament::setTenant($this->org->refresh());

    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Brown']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);
    $this->carl = User::factory()->onboardingCompleted()->create(['name' => 'Carl Weber']);
    $this->org->members()->attach($this->carl, ['role' => OrganizationRole::Member->value]);

    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $invoice = fn (?User $creator): Invoice => Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $creator?->id,
        'status' => InvoiceStatus::Sent,
        'amount' => 10000,
        'amount_paid' => 0,
    ]);

    $entry = function (Invoice $invoice, string $amount, string $paidOn, bool $refund = false): void {
        $factory = InvoicePayment::factory();

        ($refund ? $factory->refund() : $factory)->create(['invoice_id' => $invoice->id, 'amount' => $amount, 'paid_on' => $paidOn]);
    };

    $this->p1 = $invoice($this->user);
    $entry($this->p1, '500.00', '2026-10-05');
    $this->p2 = $invoice(null);
    $entry($this->p2, '600.00', '2026-09-30');
    $entry($this->p2, '100.00', '2026-10-31', refund: true);
    $this->p3 = $invoice($this->carl);
    $entry($this->p3, '200.00', '2026-11-01');
    $this->p4 = $invoice(null);
    $this->p5 = $invoice($this->bob);
    $entry($this->p5, '100.00', '2026-10-02');
    $entry($this->p5, '100.00', '2026-10-20');

    $this->all = [$this->p1, $this->p2, $this->p3, $this->p4, $this->p5];

    $this->indicators = fn ($page): array => collect($page->instance()->getTable()->getFilter('paid_between')->getIndicators())
        ->map(fn ($indicator): string => (string) $indicator->getLabel())
        ->all();
});

afterEach(function () {
    Filament::setTenant(null);
});

it('picks the invoices with a payment or a refund in the range', function (?string $from, ?string $until, array $visible) {
    $page = Livewire::test(ListInvoices::class)
        ->set('tableFilters.paid_between.from', $from)
        ->set('tableFilters.paid_between.until', $until);

    $visible = array_map(fn (string $name): Invoice => $this->{$name}, $visible);
    $hidden = array_values(array_filter($this->all, fn (Invoice $invoice): bool => ! in_array($invoice, $visible, false)));

    $page->assertCountTableRecords(count($visible))
        ->assertCanSeeTableRecords($visible)
        ->assertCanNotSeeTableRecords($hidden);
})->with([
    'the whole of October' => ['2026-10-01', '2026-10-31', ['p1', 'p2', 'p5']],
    'from the last day of October' => ['2026-10-31', null, ['p2', 'p3']],
    'until the last day of September' => [null, '2026-09-30', ['p2']],
    'a single day without payment' => ['2026-10-01', '2026-10-01', []],
    'a single day with a payment' => ['2026-10-05', '2026-10-05', ['p1']],
]);

it('lists an invoice with several rows in the range once', function () {
    Livewire::test(ListInvoices::class)
        ->set('tableFilters.paid_between.from', '2026-10-01')
        ->set('tableFilters.paid_between.until', '2026-10-31')
        ->assertCountTableRecords(3);
});

it('shows a badge for each bound and removes only its own', function () {
    $page = Livewire::test(ListInvoices::class)
        ->set('tableFilters.paid_between.from', '2026-10-01')
        ->set('tableFilters.paid_between.until', '2026-10-31');

    expect(($this->indicators)($page))->toBe(['Payment or refund from Oct 1, 2026', 'Payment or refund until Oct 31, 2026'])
        ->and(collect($page->instance()->getTable()->getFilter('paid_between')->getIndicators())->map(fn ($indicator): ?string => $indicator->getRemoveField())->all())->toBe(['from', 'until']);

    $page->call('removeTableFilter', 'paid_between', 'until');

    expect($page->get('tableFilters.paid_between.from'))->toBe('2026-10-01')
        ->and($page->get('tableFilters.paid_between.until'))->toBeNull();

    $page->assertCanSeeTableRecords([$this->p1, $this->p2, $this->p3, $this->p5])->assertCanNotSeeTableRecords([$this->p4]);
});

it('warns when the range is reversed and matches nothing', function () {
    $page = Livewire::test(ListInvoices::class)
        ->set('tableFilters.paid_between.from', '2026-10-31')
        ->set('tableFilters.paid_between.until', '2026-10-01');

    expect(last(($this->indicators)($page)))->toBe('From is after Until — no invoice can match')
        ->and(collect($page->instance()->getTable()->getFilter('paid_between')->getIndicators())->last()->getColor())->toBe('warning');

    $page->assertCountTableRecords(0);
});

it('ignores a junk or out-of-range date', function (string $date) {
    $page = Livewire::withQueryParams(['filters' => ['paid_between' => ['from' => $date]]])->test(ListInvoices::class)->assertSuccessful();

    expect(($this->indicators)($page))->toBe([]);

    $page->assertCountTableRecords(5)->assertCanSeeTableRecords($this->all);
})->with(['2026-02-30', '0001-01-01', '3000-01-01', 'abc']);

it('does not show another organization invoice paid in the range', function () {
    $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $globex = $carol->personalOrganization();
    $foreign = Invoice::factory()->create([
        'organization_id' => $globex->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $globex->id])->id,
        'created_by' => $carol->id,
        'status' => InvoiceStatus::Sent,
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $foreign->id, 'amount' => '50.00', 'paid_on' => '2026-10-10']);

    Livewire::test(ListInvoices::class)
        ->set('tableFilters.paid_between.from', '2026-10-01')
        ->set('tableFilters.paid_between.until', '2026-10-31')
        ->assertCountTableRecords(3)
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('offers the creators of the invoices, former members last', function () {
    $this->org->members()->detach($this->carl);

    expect(OwnerOptions::forFilter(Invoice::class, 'created_by'))->toBe([
        $this->user->id => 'Alice Archer',
        $this->bob->id => 'Bob Brown',
        $this->carl->id => 'Carl Weber (no longer a member)',
    ]);
});

it('does not offer a former member who created no invoice here', function () {
    $dave = User::factory()->onboardingCompleted()->create(['name' => 'Dave Day']);

    expect(OwnerOptions::forFilter(Invoice::class, 'created_by'))->not->toHaveKey($dave->id);
});

it('filters the invoices by creator', function () {
    Livewire::test(ListInvoices::class)
        ->set('tableFilters.created_by.value', (string) $this->bob->id)
        ->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$this->p5])
        ->assertCanNotSeeTableRecords([$this->p1, $this->p2, $this->p3, $this->p4]);
});

it('combines the creator and the payment date', function () {
    Livewire::test(ListInvoices::class)
        ->set('tableFilters.created_by.value', (string) $this->carl->id)
        ->set('tableFilters.paid_between.from', '2026-10-01')
        ->set('tableFilters.paid_between.until', '2026-10-31')
        ->assertCountTableRecords(0);
});

it('does not list the creators of another organization', function () {
    $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Carol Globex']);
    $globex = $carol->personalOrganization();
    Invoice::factory()->create([
        'organization_id' => $globex->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $globex->id])->id,
        'created_by' => $carol->id,
        'status' => InvoiceStatus::Sent,
    ]);

    expect(OwnerOptions::forFilter(Invoice::class, 'created_by'))->not->toHaveKey($carol->id);
});

it('offers in the creator filter exactly the creators of the organization', function () {
    $this->org->members()->detach($this->carl);

    $options = Livewire::test(ListInvoices::class)->instance()->getTable()->getFilter('created_by')->getOptions();

    expect($options)->toBe([
        $this->user->id => 'Alice Archer',
        $this->bob->id => 'Bob Brown',
        $this->carl->id => 'Carl Weber (no longer a member)',
    ]);
});
