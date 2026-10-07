<?php

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
    ]);
});

test('the invoice form refuses an amount above the column size', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'contact_id' => $this->contact->id,
            'deal_id' => $this->deal->id,
            'amount' => 100000000,
            'currency' => 'EUR',
            'issued_at' => today()->format('Y-m-d'),
            'due_at' => today()->addDays(30)->format('Y-m-d'),
        ])
        ->call('create')
        ->assertHasFormErrors(['amount' => 'max']);

    expect(Invoice::query()->count())->toBe(0);
});

test('the invoice form accepts the largest amount the column holds', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'contact_id' => $this->contact->id,
            'deal_id' => $this->deal->id,
            'amount' => '99999999.99',
            'currency' => 'EUR',
            'issued_at' => today()->format('Y-m-d'),
            'due_at' => today()->addDays(30)->format('Y-m-d'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Invoice::query()->firstOrFail()->amount)->toBe('99999999.99');
});

test('the amount paid is limited too', function () {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'amount' => 1000,
        'amount_paid' => 0,
        'status' => InvoiceStatus::Sent,
    ]);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->fillForm(['amount_paid' => 100000000])
        ->call('save')
        ->assertHasFormErrors(['amount_paid' => 'max']);

    expect($invoice->refresh()->amount_paid)->toBe('0.00');
});
