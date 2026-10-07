<?php

use App\Enums\Currency;
use App\Filament\Pages\Tenancy\EditOrganization;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
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
    it('keeps the currency on the organization only', function () {
        expect(Schema::hasColumn('organizations', 'currency'))->toBeTrue()
            ->and(Schema::hasColumn('deals', 'currency'))->toBeFalse()
            ->and(Schema::hasColumn('invoices', 'currency'))->toBeFalse();
    });
});
