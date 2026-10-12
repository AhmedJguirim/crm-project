<?php

use App\Enums\InvoiceStatus;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
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
});

test('invoices list page loads', function () {
    Livewire::test(ListInvoices::class)
        ->assertOk();
});

test('invoices create page loads', function () {
    Livewire::test(CreateInvoice::class)
        ->assertOk();
});

test('invoices edit page loads', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Draft,
    ]);

    Livewire::test(EditInvoice::class, ['record' => $invoice->id])
        ->assertOk();
});

test('invoices view page loads', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Draft,
    ]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->assertOk();
});

test('mark as paid action sets status and paid date', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Sent,
        'paid_at' => null,
    ]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->id])
        ->callAction('markAsPaid')
        ->assertHasNoActionErrors();

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->paid_at)->not->toBeNull();
});
