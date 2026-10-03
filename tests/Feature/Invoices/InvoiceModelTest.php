<?php

use App\Enums\InvoiceStatus;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('scope overdue returns overdue sent partial or status overdue invoices', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $overdueSent = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Sent,
        'due_at' => today()->subDay(),
    ]);

    $explicitOverdue = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Overdue,
        'due_at' => today()->addDay(),
    ]);

    Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Paid,
        'due_at' => today()->subDay(),
    ]);

    $ids = Invoice::query()->overdue()->pluck('id')->all();

    expect($ids)
        ->toContain($overdueSent->id)
        ->toContain($explicitOverdue->id)
        ->toHaveCount(2);
});

test('scope outstanding includes sent partial and overdue only', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Draft]);
    $sent = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Sent]);
    $partial = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Partial]);
    $overdue = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Overdue]);
    Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Paid]);
    Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id, 'status' => InvoiceStatus::Cancelled]);

    $ids = Invoice::query()->outstanding()->pluck('id')->all();

    expect($ids)
        ->toContain($sent->id)
        ->toContain($partial->id)
        ->toContain($overdue->id)
        ->toHaveCount(3);
});

test('invoice number is unique per organization', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'invoice_number' => 'INV-2026-001',
    ]);

    expect(function () use ($contact): void {
        Invoice::factory()->create([
            'organization_id' => $this->org->id,
            'contact_id' => $contact->id,
            'invoice_number' => 'INV-2026-001',
        ]);
    })->toThrow(QueryException::class);
});

test('invoice stores payment terms and amount paid', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'amount' => 10000.00,
        'amount_paid' => 3500.00,
        'payment_terms' => 45,
        'status' => InvoiceStatus::Partial,
    ]);

    $invoice->refresh();

    expect((float) $invoice->amount)->toBe(10000.00)
        ->and((float) $invoice->amount_paid)->toBe(3500.00)
        ->and($invoice->payment_terms)->toBe(45)
        ->and((float) $invoice->amount - (float) $invoice->amount_paid)->toBe(6500.00);
});

test('invoice amount paid defaults to zero', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Draft,
    ]);

    expect((float) $invoice->amount_paid)->toBeGreaterThanOrEqual(0.00);
});

test('tenant scoping returns only current organization invoices', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $visible = Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();
    $otherContact = Contact::factory()->create(['organization_id' => $otherOrg->id]);
    Invoice::factory()->create(['organization_id' => $otherOrg->id, 'contact_id' => $otherContact->id]);

    expect(Invoice::query()->pluck('id')->all())
        ->toBe([$visible->id]);
});
