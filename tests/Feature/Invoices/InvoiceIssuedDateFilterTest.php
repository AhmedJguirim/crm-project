<?php

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->january = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Sent, 'issued_at' => '2026-01-10']);
    $this->march = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Sent, 'issued_at' => '2026-03-10']);

    $this->indicators = fn ($page): array => collect($page->instance()->getTable()->getFilter('issued_at_range')->getIndicators())
        ->map(fn ($indicator): string => (string) $indicator->getLabel())
        ->all();
});

it('shows a badge for each bound', function () {
    $page = Livewire::test(ListInvoices::class)
        ->set('tableFilters.issued_at_range.from', '2026-01-01')
        ->set('tableFilters.issued_at_range.until', '2026-03-02');

    expect(($this->indicators)($page))->toBe(['Issued from Jan 1, 2026', 'Issued until Mar 2, 2026']);

    $page->assertCanSeeTableRecords([$this->january])->assertCanNotSeeTableRecords([$this->march]);
});

it('shows only the badge of the bound that is set', function () {
    $page = Livewire::test(ListInvoices::class)->set('tableFilters.issued_at_range.until', '2026-03-02');

    expect(($this->indicators)($page))->toBe(['Issued until Mar 2, 2026']);
});

it('makes each badge remove only its own bound', function () {
    $page = Livewire::test(ListInvoices::class)
        ->set('tableFilters.issued_at_range.from', '2026-01-01')
        ->set('tableFilters.issued_at_range.until', '2026-03-02');

    $removed = collect($page->instance()->getTable()->getFilter('issued_at_range')->getIndicators())
        ->map(fn ($indicator): ?string => $indicator->getRemoveField())
        ->all();

    expect($removed)->toBe(['from', 'until']);
});

it('keeps the other bound when one badge is removed', function () {
    $page = Livewire::test(ListInvoices::class)
        ->set('tableFilters.issued_at_range.from', '2026-01-01')
        ->set('tableFilters.issued_at_range.until', '2026-03-02');

    $page->call('removeTableFilter', 'issued_at_range', 'until');

    expect(($this->indicators)($page))->toBe(['Issued from Jan 1, 2026'])
        ->and($page->get('tableFilters.issued_at_range.until'))->toBeNull()
        ->and($page->get('tableFilters.issued_at_range.from'))->toBe('2026-01-01');

    $page->assertCanSeeTableRecords([$this->january, $this->march]);
});

it('has no badge for an empty or impossible bound', function (mixed $until) {
    $page = Livewire::test(ListInvoices::class)->set('tableFilters.issued_at_range.until', $until);

    expect(($this->indicators)($page))->toBe([]);
})->with([null, '', 'abc', '2026-02-30']);

it('drops an impossible date from the URL and keeps a real one', function () {
    $impossible = Livewire::withQueryParams(['filters' => ['issued_at_range' => ['until' => '2026-02-30']]])->test(ListInvoices::class);

    expect($impossible->get('tableFilters.issued_at_range.until'))->toBeNull()
        ->and(($this->indicators)($impossible))->toBe([]);

    $impossible->assertCanSeeTableRecords([$this->january, $this->march]);

    $real = Livewire::withQueryParams(['filters' => ['issued_at_range' => ['until' => '2026-02-28']]])->test(ListInvoices::class);

    expect(($this->indicators)($real))->toBe(['Issued until Feb 28, 2026']);

    $real->assertCanSeeTableRecords([$this->january])->assertCanNotSeeTableRecords([$this->march]);
});
