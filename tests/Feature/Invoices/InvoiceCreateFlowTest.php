<?php

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
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
        'stage' => DealStage::ProposalSent,
        'status' => DealStatus::Open,
    ]);
});

describe('prefilling the form from the query string', function () {
    beforeEach(function () {
        $this->deal->update(['title' => 'TechCorp Infra Expansion', 'value' => '85000.00']);
    });

    $blank = ['contact_id' => null, 'deal_id' => null, 'amount' => null];

    $openWith = fn (array $params): Testable => Livewire::withQueryParams($params)->test(CreateInvoice::class);

    test('create invoice page pre-fills from deal query param', function () use ($openWith) {
        $openWith(['deal' => (string) $this->deal->id])
            ->assertOk()
            ->assertFormSet([
                'contact_id' => $this->contact->id,
                'deal_id' => $this->deal->id,
                'amount' => '85000.00',
            ]);
    });

    test('create invoice page pre-fills from contact query param', function () use ($openWith, $blank) {
        $openWith(['contact' => (string) $this->contact->id])
            ->assertOk()
            ->assertFormSet(['contact_id' => $this->contact->id] + $blank);
    });

    test('the prefill keeps the other form defaults', function () use ($openWith) {
        $this->travelTo(Carbon::parse('2026-03-10 12:00:00', 'UTC'));

        $page = $openWith(['deal' => (string) $this->deal->id])
            ->assertFormSet([
                'invoice_number' => 'INV-2026-001',
                'payment_terms' => 30,
                'due_at' => '2026-04-09',
            ]);

        expect(Carbon::parse($page->get('data.issued_at'))->toDateString())->toBe('2026-03-10');
    });

    test('creating from the prefilled form saves the deal data', function () use ($openWith) {
        $openWith(['deal' => (string) $this->deal->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::query()->sole();

        expect($invoice->contact_id)->toBe($this->contact->id)
            ->and($invoice->deal_id)->toBe($this->deal->id)
            ->and((string) $invoice->amount)->toBe('85000.00')
            ->and($invoice->status)->toBe(InvoiceStatus::Draft);
    });

    test('a won deal pre-fills too', function () use ($openWith) {
        $this->deal->update(['status' => DealStatus::Won, 'stage' => DealStage::Won, 'won_at' => now()]);

        $openWith(['deal' => (string) $this->deal->id])
            ->assertFormSet(['deal_id' => $this->deal->id, 'amount' => '85000.00']);
    });

    test('a deal without a value pre-fills the contact and the deal only', function () use ($openWith) {
        $this->deal->update(['value' => null]);

        $openWith(['deal' => (string) $this->deal->id])
            ->assertFormSet(['contact_id' => $this->contact->id, 'deal_id' => $this->deal->id, 'amount' => null]);
    });

    test('the deal wins over the contact parameter', function () use ($openWith) {
        $grace = Contact::factory()->create(['organization_id' => $this->org->id]);

        $openWith(['deal' => (string) $this->deal->id, 'contact' => (string) $grace->id])
            ->assertFormSet(['contact_id' => $this->contact->id, 'deal_id' => $this->deal->id]);
    });

    test('a deal without a live contact falls back to the contact parameter', function () use ($openWith, $blank) {
        $grace = Contact::factory()->create(['organization_id' => $this->org->id]);
        $orphan = Deal::factory()->create([
            'organization_id' => $this->org->id,
            'contact_id' => null,
            'created_by' => $this->user->id,
            'value' => '1200.00',
        ]);

        $openWith(['deal' => (string) $orphan->id, 'contact' => (string) $grace->id])
            ->assertFormSet(['contact_id' => $grace->id] + $blank);
    });

    test('a deal whose contact is soft-deleted pre-fills nothing', function () use ($openWith, $blank) {
        $this->contact->delete();

        $openWith(['deal' => (string) $this->deal->id])
            ->assertOk()
            ->assertFormSet($blank);
    });

    test('bad ids are ignored silently', function (string $param, string $value) use ($openWith, $blank) {
        $openWith([$param => $value])
            ->assertOk()
            ->assertFormSet($blank + ['invoice_number' => 'INV-'.now()->year.'-001']);
    })->with([
        'deal abc' => ['deal', 'abc'],
        'deal zero' => ['deal', '0'],
        'deal too big' => ['deal', '99999999999999999999'],
        'deal unknown' => ['deal', '999999'],
        'contact abc' => ['contact', 'abc'],
        'contact exponent' => ['contact', '1e3'],
    ]);

    test('a deal of another organization is ignored', function () use ($openWith, $blank) {
        $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
        $foreignContact = Contact::factory()->create(['organization_id' => $other->id]);
        $foreignDeal = Deal::factory()->create([
            'organization_id' => $other->id,
            'contact_id' => $foreignContact->id,
            'value' => '700.00',
        ]);

        $openWith(['deal' => (string) $foreignDeal->id])
            ->assertOk()
            ->assertFormSet($blank);
    });

    test('a deal of another organization is ignored even when it points at my contact', function () use ($openWith, $blank) {
        $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
        $foreignDeal = Deal::factory()->create([
            'organization_id' => $other->id,
            'contact_id' => $this->contact->id,
            'value' => '700.00',
        ]);

        $openWith(['deal' => (string) $foreignDeal->id])
            ->assertOk()
            ->assertFormSet($blank);
    });

    test('a contact of another organization is ignored', function () use ($openWith, $blank) {
        $other = User::factory()->withPersonalOrganization()->create()->personalOrganization();
        $foreignContact = Contact::factory()->create(['organization_id' => $other->id]);

        $openWith(['contact' => (string) $foreignContact->id])
            ->assertOk()
            ->assertFormSet($blank);
    });

    test('a soft-deleted contact is ignored', function () use ($openWith, $blank) {
        $gone = Contact::factory()->create(['organization_id' => $this->org->id]);
        $gone->delete();

        $openWith(['contact' => (string) $gone->id])
            ->assertOk()
            ->assertFormSet($blank);
    });

    test('a member can open the prefilled form', function () use ($openWith) {
        $member = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($member, ['role' => OrganizationRole::Member->value]);
        $this->actingAs($member);
        Filament::setTenant($this->org);

        $openWith(['deal' => (string) $this->deal->id])
            ->assertFormSet([
                'contact_id' => $this->contact->id,
                'deal_id' => $this->deal->id,
                'amount' => '85000.00',
            ]);
    });

    test('a viewer is still refused', function () use ($openWith) {
        $viewer = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($viewer, ['role' => OrganizationRole::Viewer->value]);
        $this->actingAs($viewer);
        Filament::setTenant($this->org);

        $openWith(['deal' => (string) $this->deal->id])->assertForbidden();
    });
});

test('creating invoice always sets draft status', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'contact_id' => $this->contact->id,
            'deal_id' => $this->deal->id,
            'amount' => 5000.00,
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
