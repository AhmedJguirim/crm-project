<?php

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function invoiceFormComponent(Testable $page, string $name): Field
{
    return collect($page->instance()->getSchema('form')->getFlatComponents())
        ->first(fn ($component): bool => $component instanceof Field && $component->getName() === $name);
}

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Eur]);
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'status' => InvoiceStatus::Partial,
        'amount' => 2000,
        'amount_paid' => 500,
    ]);
});

test('the invoice forms have no currency field and prefix the amounts with the organization currency', function () {
    $create = Livewire::test(CreateInvoice::class)->assertFormFieldDoesNotExist('currency');

    expect(invoiceFormComponent($create, 'amount')->getPrefixLabel())->toBe('EUR');

    $edit = Livewire::test(EditInvoice::class, ['record' => $this->invoice->id])->assertFormFieldDoesNotExist('currency');

    expect(invoiceFormComponent($edit, 'amount_paid')->getPrefixLabel())->toBe('EUR');
    $edit->assertSee('Balance remaining: 1,500.00 EUR');
});

test('choosing a deal fills the amount from its value and the invoice carries no currency', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'value' => 750,
    ]);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['contact_id' => $this->contact->id])
        ->set('data.deal_id', $deal->id)
        ->assertFormSet(['amount' => '750.00'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Invoice::query()->where('deal_id', $deal->id)->firstOrFail()->getAttributes())->not->toHaveKey('currency');
});

test('the deal options of the invoice form show the organization currency', function () {
    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Priced deal',
        'value' => 1000,
    ]);

    $page = Livewire::test(CreateInvoice::class)->fillForm(['contact_id' => $this->contact->id]);

    expect(array_values(invoiceFormComponent($page, 'deal_id')->getOptions()))->toBe(['Priced deal — 1,000.00 EUR']);
});

test('the invoices table and the invoice page show the amount in the organization currency', function () {
    Livewire::test(ListInvoices::class)->assertSee('€2,000.00');

    Livewire::test(ViewInvoice::class, ['record' => $this->invoice->id])
        ->assertSee('€2,000.00')
        ->assertSee('€500.00')
        ->assertSee('1,500.00 EUR')
        ->assertDontSee('$2,000.00');
});

test('the invoice pdf is in the currency of its organization', function () {
    $html = view('pdf.invoice', [
        'invoice' => $this->invoice->load(['contact', 'deal', 'organization']),
        'organization' => $this->org,
    ])->render();

    expect($html)->toContain('€2,000.00')
        ->toContain('€1,500.00')
        ->not->toContain('$2,000.00');
});
