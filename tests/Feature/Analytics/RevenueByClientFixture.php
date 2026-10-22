<?php

namespace Tests\Feature\Analytics;

use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

/**
 * The revenue by client scenario shared by the service and widget tests: organization "Acme" (Europe/Paris, USD) with
 * Alice (owner) and Bob (member), four companies (Umbrella soft-deleted), four contacts, invoices A to G with their
 * ledger rows (see `invoices()`), and one paid Globex invoice, with "now" = 2026-10-21 10:00 UTC.
 */
final class RevenueByClientFixture
{
    public Organization $org;

    public Organization $globex;

    public User $alice;

    public User $bob;

    public User $carol;

    /** @var array<string, Company> */
    public array $companies = [];

    /** @var array<string, Contact> */
    public array $contacts = [];

    /** @var array<string, Invoice> */
    public array $invoices = [];

    public static function build(bool $withInvoices = true): self
    {
        $fixture = new self;

        $fixture->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Anders']);
        $fixture->org = $fixture->alice->personalOrganization();
        $fixture->org->update(['timezone' => 'Europe/Paris']);

        $fixture->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Brown']);
        $fixture->org->members()->attach($fixture->bob, ['role' => OrganizationRole::Member->value]);

        $fixture->carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Carol Clark']);
        $fixture->globex = $fixture->carol->personalOrganization();

        test()->actingAs($fixture->alice);
        Filament::setTenant($fixture->org->refresh());

        if ($withInvoices) {
            $fixture->invoices();
        }

        test()->travelTo(self::now());

        return $fixture;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC');
    }

    public function company(string $name, ?Organization $organization = null): Company
    {
        return $this->companies[$name] = Company::factory()->create(['organization_id' => ($organization ?? $this->org)->id, 'name' => $name]);
    }

    /**
     * @param  list<string>  $companyNames  companies of the fixture the contact is linked to
     */
    public function contact(string $name, array $companyNames = [], ?Organization $organization = null): Contact
    {
        $contact = Contact::factory()->create(['organization_id' => ($organization ?? $this->org)->id, 'name' => $name]);

        foreach ($companyNames as $companyName) {
            $contact->companies()->attach($this->companies[$companyName]);
        }

        return $this->contacts[$name] = $contact;
    }

    public function deal(string $title, ?Company $company): Deal
    {
        return Deal::factory()->create([
            'organization_id' => $this->org->id,
            'title' => $title,
            'company_id' => $company?->id,
            'created_by' => $this->alice->id,
        ]);
    }

    public function invoice(string $key, Contact $contact, ?Deal $deal, ?User $creator, InvoiceStatus $status, string $amount, string $amountPaid, ?Organization $organization = null): Invoice
    {
        return $this->invoices[$key] = Invoice::factory()->create([
            'organization_id' => ($organization ?? $this->org)->id,
            'contact_id' => $contact->id,
            'deal_id' => $deal?->id,
            'created_by' => $creator?->id,
            'status' => $status,
            'amount' => $amount,
            'amount_paid' => $amountPaid,
            'due_at' => '2026-11-15',
        ]);
    }

    public function ledger(Invoice $invoice, string $amount, string $paidOn, bool $refund = false): InvoicePayment
    {
        $factory = InvoicePayment::factory();

        return ($refund ? $factory->refund() : $factory)->create(['invoice_id' => $invoice->id, 'amount' => $amount, 'paid_on' => $paidOn]);
    }

    private function invoices(): void
    {
        $this->company('Northwind');
        $this->company('Initech');
        $this->company('Hooli');
        $umbrella = $this->company('Umbrella');

        $nora = $this->contact('Nora Nash', ['Northwind']);
        $ivan = $this->contact('Ivan Ivanov', ['Initech', 'Hooli']);
        $zoe = $this->contact('Zoe Zimmer');
        $dora = $this->contact('Dora Dunn', ['Hooli']);

        $umbrellaDeal = $this->deal('Umbrella rollout', $umbrella);
        $zoeDeal = $this->deal('Zoe pilot', null);
        $initechDeal = $this->deal('Initech audit', $this->companies['Initech']);
        $umbrella->delete();

        $a = $this->invoice('A', $nora, null, $this->alice, InvoiceStatus::Partial, '10000', '7000');
        $this->ledger($a, '1000', '2026-09-30');
        $this->ledger($a, '6000', '2026-10-05');

        $b = $this->invoice('B', $dora, $umbrellaDeal, $this->bob, InvoiceStatus::Paid, '4000', '4000');
        $this->ledger($b, '4000', '2026-10-10');

        $c = $this->invoice('C', $ivan, null, $this->alice, InvoiceStatus::Partial, '5000', '2500');
        $this->ledger($c, '2500', '2026-10-01');

        $d = $this->invoice('D', $zoe, $zoeDeal, $this->alice, InvoiceStatus::Partial, '3000', '2000');
        $this->ledger($d, '3000', '2026-10-15');
        $this->ledger($d, '1000', '2026-10-20', refund: true);

        $this->invoice('E', $nora, $initechDeal, $this->bob, InvoiceStatus::Sent, '1500', '0');

        $g = $this->invoice('G', $nora, null, $this->alice, InvoiceStatus::Cancelled, '800', '0');
        $this->ledger($g, '800', '2026-10-02');
        $this->ledger($g, '800', '2026-10-03', refund: true);

        $globexContact = $this->contact('Gus Globex', [], $this->globex);
        $x = $this->invoice('X', $globexContact, null, $this->carol, InvoiceStatus::Paid, '9999', '9999', $this->globex);
        $this->ledger($x, '9999', '2026-10-05');
    }
}
