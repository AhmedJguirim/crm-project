<?php

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Filament\Pages\Tenancy\EditOrganization;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Sami Ben Ali']);
    $this->makeInvoice = fn (InvoiceStatus $status, ?Currency $currency = null, ?string $rate = null): Invoice => Invoice::factory()
        ->when($currency, fn ($factory) => $factory->inCurrency($currency, $rate))
        ->create([
            'organization_id' => $this->org->id,
            'contact_id' => $this->contact->id,
            'invoice_number' => 'INV-2026-001',
            'amount' => 1000,
            'amount_paid' => 0,
            'status' => $status,
        ]);
    $this->lockedHelp = 'Used for every deal value and invoice amount. It can\'t be changed once an invoice has been issued, because each issued invoice keeps its exchange rate to this currency.';
    $this->unlockedHelp = 'Used for every deal value and invoice amount. Changing it does not convert existing amounts.';
    $this->refusal = 'The currency can\'t be changed once an invoice has been issued: each issued invoice keeps its exchange rate to USD.';
});

describe('the model', function () {
    it('defaults to USD', function () {
        $organization = Organization::factory()->create()->fresh();

        expect($organization->currency)->toBe(Currency::Usd)
            ->and($organization->currencyCode())->toBe('USD')
            ->and($this->org->fresh()->currency)->toBe(Currency::Usd);
    });

    it('falls back to USD on an in-memory model without the attribute', function () {
        expect((new Organization)->currencyCode())->toBe('USD');
    });

    it('gives the code of the chosen currency', function (Currency $currency) {
        expect(Organization::factory()->create(['currency' => $currency])->fresh()->currencyCode())->toBe($currency->value);
    })->with(Currency::cases());

    it('has human labels for the select', function () {
        expect(Currency::Eur->getLabel())->toBe('Euro (EUR)')
            ->and(Currency::Usd->getLabel())->toBe('US dollar (USD)');
    });

    it('has a label, a colour and an icon for TND', function () {
        expect(Currency::Tnd->getLabel())->toBe('Tunisian dinar (TND)')
            ->and(Currency::Tnd->getColor())->toBe('gray')
            ->and(Currency::Tnd->getIcon())->toBe(Heroicon::OutlinedBanknotes);
    });
});

describe('the settings page', function () {
    it('shows the current currency', function () {
        Livewire::test(EditOrganization::class)->assertFormSet(['currency' => Currency::Usd]);
    });

    it('lets the currency be changed', function () {
        Livewire::test(EditOrganization::class)
            ->fillForm(['currency' => Currency::Eur])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->org->fresh()->currency)->toBe(Currency::Eur);
    });

    it('refuses an unknown currency', function () {
        Livewire::test(EditOrganization::class)
            ->fillForm(['currency' => 'XXX'])
            ->call('save')
            ->assertHasFormErrors(['currency']);

        expect($this->org->fresh()->currency)->toBe(Currency::Usd);
    });

    it('requires a currency', function () {
        Livewire::test(EditOrganization::class)
            ->fillForm(['currency' => null])
            ->call('save')
            ->assertHasFormErrors(['currency' => 'required']);
    });

    it('says that changing it converts nothing', function () {
        Livewire::test(EditOrganization::class)
            ->assertSee('Used for every deal value and invoice amount. Changing it does not convert existing amounts.');
    });
});

describe('registering an organization', function () {
    it('stores the chosen currency', function () {
        Livewire::test(RegisterOrganization::class)
            ->fillForm(['name' => 'Euro Co', 'timezone' => 'Europe/Paris', 'currency' => Currency::Eur])
            ->call('register')
            ->assertHasNoFormErrors();

        expect(Organization::where('name', 'Euro Co')->sole()->currency)->toBe(Currency::Eur);
    });

    it('starts with USD', function () {
        Livewire::test(RegisterOrganization::class)->assertFormSet(['currency' => Currency::Usd]);

        Livewire::test(RegisterOrganization::class)
            ->fillForm(['name' => 'Default Co'])
            ->call('register')
            ->assertHasNoFormErrors();

        expect(Organization::where('name', 'Default Co')->sole()->currency)->toBe(Currency::Usd);
    });
});

describe('the schema', function () {
    it('keeps the deal values in the organization currency and gives the currency and rate to invoices only', function () {
        expect(Schema::hasColumn('organizations', 'currency'))->toBeTrue()
            ->and(Schema::hasColumn('deals', 'currency'))->toBeFalse()
            ->and(Schema::hasColumn('invoices', 'currency'))->toBeTrue()
            ->and(Schema::hasColumn('invoices', 'exchange_rate'))->toBeTrue();
    });
});

describe('once an invoice has been issued', function () {
    it('lets the currency change while only drafts exist', function () {
        $draft = ($this->makeInvoice)(InvoiceStatus::Draft);

        Livewire::test(EditOrganization::class)
            ->assertFormFieldEnabled('currency')
            ->assertSee($this->unlockedHelp)
            ->fillForm(['currency' => Currency::Eur])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->org->fresh()->currency)->toBe(Currency::Eur)
            ->and($draft->fresh()->currency)->toBe(Currency::Usd);
    });

    it('locks the currency for every status but Draft', function (InvoiceStatus $status) {
        ($this->makeInvoice)($status);

        Livewire::test(EditOrganization::class)
            ->assertFormFieldDisabled('currency')
            ->assertSee($this->lockedHelp)
            ->assertDontSee($this->unlockedHelp);
    })->with([
        InvoiceStatus::Sent,
        InvoiceStatus::Paid,
        InvoiceStatus::Partial,
        InvoiceStatus::Overdue,
        InvoiceStatus::Cancelled,
    ]);

    it('locks it for a foreign-currency invoice too', function () {
        ($this->makeInvoice)(InvoiceStatus::Sent, Currency::Eur, '1.08');

        Livewire::test(EditOrganization::class)->assertFormFieldDisabled('currency');
    });

    it('still saves the other fields while locked', function () {
        ($this->makeInvoice)(InvoiceStatus::Sent);

        Livewire::test(EditOrganization::class)
            ->fillForm(['description' => 'Freelance studio'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->org->fresh())
            ->description->toBe('Freelance studio')
            ->currency->toBe(Currency::Usd);
    });

    it('unlocks again once the last issued invoice is deleted', function () {
        $invoice = ($this->makeInvoice)(InvoiceStatus::Sent);
        $invoice->delete();

        Livewire::test(EditOrganization::class)->assertFormFieldEnabled('currency');
    });

    it('refuses a tab opened before the first invoice was issued', function () {
        $page = Livewire::test(EditOrganization::class);

        ($this->makeInvoice)(InvoiceStatus::Sent);

        $page->fillForm(['currency' => Currency::Eur])
            ->call('save')
            ->assertHasFormErrors(['currency'])
            ->assertSee($this->refusal);

        expect($this->org->fresh()->currency)->toBe(Currency::Usd);
    });

    it('lets a locked page save with the unchanged currency', function () {
        ($this->makeInvoice)(InvoiceStatus::Sent);

        Livewire::test(EditOrganization::class)
            ->set('data.currency', 'USD')
            ->call('save')
            ->assertHasNoFormErrors();
    });

    it('does not lock the registration of a new organization', function () {
        ($this->makeInvoice)(InvoiceStatus::Sent);

        Livewire::test(RegisterOrganization::class)
            ->fillForm(['name' => 'Euro Co', 'timezone' => 'Europe/Paris', 'currency' => Currency::Eur])
            ->call('register')
            ->assertHasNoFormErrors();

        expect(Organization::where('name', 'Euro Co')->sole()->currency)->toBe(Currency::Eur);
    });
});

describe('abuse', function () {
    it('an owner forging the disabled currency select is refused', function () {
        ($this->makeInvoice)(InvoiceStatus::Sent);

        Livewire::test(EditOrganization::class)
            ->set('data.currency', 'EUR')
            ->set('data.description', 'Forged')
            ->call('save')
            ->assertHasFormErrors(['currency'])
            ->assertSee($this->refusal);

        expect($this->org->fresh())
            ->currency->toBe(Currency::Usd)
            ->description->not->toBe('Forged');
    });

    it('an owner forging a currency the app does not know is refused without a 500', function () {
        ($this->makeInvoice)(InvoiceStatus::Sent);

        Livewire::test(EditOrganization::class)
            ->set('data.currency', 'XXX')
            ->call('save')
            ->assertHasFormErrors(['currency']);

        expect($this->org->fresh()->currency)->toBe(Currency::Usd);
    });

    it("another organization's issued invoices don't lock mine", function () {
        $globex = Organization::factory()->create(['currency' => Currency::Usd]);
        app(TenantContext::class)->run($globex->id, fn () => Invoice::factory()->create([
            'organization_id' => $globex->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $globex->id])->id,
            'invoice_number' => 'INV-2026-001',
            'amount' => 1000,
            'amount_paid' => 0,
            'status' => InvoiceStatus::Sent,
        ]));

        Livewire::test(EditOrganization::class)
            ->assertFormFieldEnabled('currency')
            ->fillForm(['currency' => Currency::Eur])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->org->fresh()->currency)->toBe(Currency::Eur)
            ->and($globex->fresh()->currency)->toBe(Currency::Usd);
    });
});
