<?php

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Filament\Widgets\RevenueOverviewWidget;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

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

test('revenue overview widget shows expected revenue metrics', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Paid,
        'amount' => 5000,
        'paid_at' => now()->subDays(2),
    ]);

    Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Paid,
        'amount' => 3000,
        'paid_at' => now()->subMonth(),
    ]);

    Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Sent,
        'amount' => 1200,
        'amount_paid' => 0,
        'due_at' => now()->addDays(5),
    ]);

    Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Overdue,
        'amount' => 800,
        'amount_paid' => 0,
        'due_at' => now()->subDays(3),
    ]);

    Livewire::test(RevenueOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Paid This Month')
        ->assertSee('$5,000.00')
        ->assertSee('YTD Revenue')
        ->assertSee('$8,000.00')
        ->assertSee('View Invoices')
        ->assertDontSee('Outstanding')
        ->assertDontSee('Overdue Invoices');
});

test('revenue overview widget is tenant scoped', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Paid,
        'amount' => 1000,
        'paid_at' => now()->subDays(1),
    ]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();
    $otherContact = Contact::factory()->create(['organization_id' => $otherOrg->id]);

    Invoice::factory()->create([
        'organization_id' => $otherOrg->id,
        'contact_id' => $otherContact->id,
        'status' => InvoiceStatus::Paid,
        'amount' => 9000,
        'paid_at' => now()->subDays(1),
    ]);

    Livewire::test(RevenueOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('$1,000.00')
        ->assertDontSee('$9,000.00');
});

test('revenue overview widget shows every card in the currency of the organization', function () {
    $this->org->update(['currency' => Currency::Eur]);
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $rows = [
        [InvoiceStatus::Paid, 5000, ['paid_at' => now()->subDays(2)]],
        [InvoiceStatus::Paid, 3000, ['paid_at' => now()->subDay()]],
        [InvoiceStatus::Paid, 1000, ['paid_at' => now()->subMonth()]],
        [InvoiceStatus::Sent, 1200, ['due_at' => now()->addDays(5)]],
        [InvoiceStatus::Partial, 300, ['due_at' => now()->addDays(5), 'amount_paid' => 100]],
        [InvoiceStatus::Overdue, 800, ['due_at' => now()->subDays(3)]],
    ];

    foreach ($rows as [$status, $amount, $dates]) {
        Invoice::factory()->create([
            'organization_id' => $this->org->id,
            'contact_id' => $contact->id,
            'status' => $status,
            'amount' => $amount,
            'amount_paid' => 0,
            ...$dates,
        ]);
    }

    Livewire::test(RevenueOverviewWidget::class)
        ->assertSeeInOrder(['Paid This Month', '€8,000.00', 'Paid invoices this month'])
        ->assertSeeInOrder(['YTD Revenue', '€9,000.00', 'Paid invoices this year'])
        ->assertDontSee('Mixed currencies')
        ->assertDontSee('$8,000.00')
        ->assertDontSee('$800.00');
});

test('revenue overview widget shows dollars for a default organization without invoices', function () {
    Livewire::test(RevenueOverviewWidget::class)
        ->assertSeeInOrder(['Paid This Month', '$0.00']);
});
