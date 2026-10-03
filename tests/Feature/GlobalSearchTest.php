<?php

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('contact resource is globally searchable by name email and phone', function () {
    expect(ContactResource::getGloballySearchableAttributes())
        ->toBe(['name', 'email', 'phone']);
});

test('deal resource is globally searchable by title and contact name', function () {
    expect(DealResource::getGloballySearchableAttributes())
        ->toBe(['title', 'contact.name']);
});

test('invoice resource is globally searchable by number and contact name', function () {
    expect(InvoiceResource::getGloballySearchableAttributes())
        ->toBe(['invoice_number', 'contact.name']);
});

test('task resource is globally searchable by title and contact name', function () {
    expect(TaskResource::getGloballySearchableAttributes())
        ->toBe(['title', 'contact.name']);
});

test('global search returns matching contacts', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Unique Searchable Name',
    ]);

    $results = ContactResource::getGlobalSearchResults('Unique Searchable');

    expect($results->count())->toBeGreaterThanOrEqual(1);
});

test('global search returns matching deals', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
        'title' => 'Unique Deal Title XYZ',
    ]);

    $results = DealResource::getGlobalSearchResults('Unique Deal Title XYZ');

    expect($results->count())->toBeGreaterThanOrEqual(1);
});

test('global search returns matching invoices', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-SEARCH-001',
    ]);

    $results = InvoiceResource::getGlobalSearchResults('INV-SEARCH-001');

    expect($results->count())->toBeGreaterThanOrEqual(1);
});
