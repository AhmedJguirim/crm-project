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

    $response->assertForbidden();
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

test('a malformed or out-of-range invoice id answers not found', function (string $id) {
    $this->get("/invoices/{$id}/pdf")->assertNotFound();
})->with(['abc', '1abc', '-1', '99999999999999999999', '9223372036854775807', '9999999999999999999', '999999999999999999', '%205', '5%20']);

test('a malformed invoice id answers not found for guests too', function () {
    auth()->logout();

    $this->get('/invoices/abc/pdf')->assertNotFound();
});

test('invoice pdf downloads with a safe file name whatever the number holds', function (string $number, string $file) {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'status' => InvoiceStatus::Sent,
        'invoice_number' => $number,
    ]);

    $this->get(route('invoices.pdf', $invoice))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertDownload($file);
})->with([
    ['INV-2026-001', 'INV-2026-001.pdf'],
    ['INV/2026/001', 'INV-2026-001.pdf'],
    ['INV\\2026\\001', 'INV-2026-001.pdf'],
    ['../../etc/passwd', 'etc-passwd.pdf'],
    ['Facture n° 7', 'Facture-n-7.pdf'],
    ["INV\"2026;\r\n001", 'INV-2026-001.pdf'],
]);

test('an invoice number made only of separators falls back to the invoice id', function () {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'status' => InvoiceStatus::Sent,
        'invoice_number' => '///',
    ]);

    $this->get(route('invoices.pdf', $invoice))
        ->assertOk()
        ->assertDownload("invoice-{$invoice->id}.pdf");
});
