<?php

use App\Enums\InvoiceStatus;
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

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);
});

test('invoice pdf can be downloaded', function () {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'status' => InvoiceStatus::Sent,
    ]);

    $response = $this->get(route('invoices.pdf', $invoice));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

test('invoice pdf includes deal info when linked', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Website Redesign Project',
    ]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'deal_id' => $deal->id,
        'status' => InvoiceStatus::Draft,
    ]);

    $response = $this->get(route('invoices.pdf', $invoice));

    $response->assertOk();
    $response->assertHeader('content-type', 'application/pdf');
});

test('invoice pdf is forbidden for other organizations', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();
    $otherContact = Contact::factory()->create(['organization_id' => $otherOrg->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $otherOrg->id,
        'contact_id' => $otherContact->id,
        'status' => InvoiceStatus::Sent,
    ]);

    $response = $this->get(route('invoices.pdf', $invoice));

    $response->assertNotFound();
});

test('invoice pdf requires authentication', function () {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'status' => InvoiceStatus::Sent,
    ]);

    auth()->logout();

    $response = $this->get(route('invoices.pdf', $invoice));

    $response->assertRedirect();
});
