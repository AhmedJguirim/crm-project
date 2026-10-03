<?php

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
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
        'value' => 5000.00,
        'currency' => 'EUR',
        'stage' => DealStage::ProposalSent,
        'status' => DealStatus::Open,
    ]);
});

test('create invoice page pre-fills from deal query param', function () {
    Livewire::test(CreateInvoice::class, ['prefillDealId' => $this->deal->id])
        ->assertOk()
        ->assertFormFieldExists('contact_id')
        ->assertFormFieldExists('deal_id');
});

test('create invoice page pre-fills from contact query param', function () {
    Livewire::test(CreateInvoice::class, ['prefillContactId' => $this->contact->id])
        ->assertOk()
        ->assertFormFieldExists('contact_id');
});

test('creating invoice always sets draft status', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'contact_id' => $this->contact->id,
            'deal_id' => $this->deal->id,
            'amount' => 5000.00,
            'currency' => 'EUR',
            'issued_at' => today()->format('Y-m-d'),
            'due_at' => today()->addDays(30)->format('Y-m-d'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::latest()->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->paid_at)->toBeNull();
});

test('creating invoice defaults to Net 30 payment terms', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'contact_id' => $this->contact->id,
            'deal_id' => $this->deal->id,
            'amount' => 5000.00,
            'currency' => 'EUR',
            'issued_at' => today()->format('Y-m-d'),
            'due_at' => today()->addDays(30)->format('Y-m-d'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::latest()->first();

    expect($invoice->payment_terms)->toBe(30)
        ->and((float) $invoice->amount_paid)->toBe(0.00);
});

test('creating invoice requires contact and amount', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'contact_id' => null,
            'amount' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'contact_id' => 'required',
            'amount' => 'required',
        ]);
});
