<?php

use App\Enums\CompanyIndustry;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->company = fn (string $name, ?CompanyIndustry $industry = null): Company => Company::factory()->create(['organization_id' => $this->org->id, 'name' => $name, 'industry' => $industry]);
});

describe('the enum', function () {
    it('has the 21 industries of the fixed list, in order', function () {
        expect(array_map(fn (CompanyIndustry $industry): string => $industry->getLabel(), CompanyIndustry::cases()))->toBe([
            'Software', 'Cloud & Hosting', 'Cybersecurity', 'Consulting', 'Finance & Banking', 'Insurance', 'Healthcare',
            'Pharma & Biotech', 'Education', 'Government & Public Sector', 'Retail & E-commerce', 'Manufacturing',
            'Energy & Utilities', 'Telecommunications', 'Media & Entertainment', 'Marketing & Advertising',
            'Transport & Logistics', 'Real Estate & Construction', 'Hospitality & Travel', 'Non-profit', 'Other',
        ])->and(CompanyIndustry::cases())->toHaveCount(21);
    });

    it('stores snake case values', function () {
        expect(CompanyIndustry::FinanceBanking->value)->toBe('finance_banking')
            ->and(CompanyIndustry::FinanceBanking->getLabel())->toBe('Finance & Banking')
            ->and(CompanyIndustry::GovernmentPublicSector->value)->toBe('government_public_sector')
            ->and(CompanyIndustry::NonProfit->value)->toBe('non_profit')
            ->and(collect(CompanyIndustry::cases())->pluck('value')->unique())->toHaveCount(21);
    });

    it('is gray with no icon, for every case', function () {
        foreach (CompanyIndustry::cases() as $industry) {
            expect($industry->getColor())->toBe('gray')
                ->and($industry->getIcon())->toBeNull();
        }
    });
});

describe('the form', function () {
    it('sets and clears the industry', function () {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Acme', 'industry' => CompanyIndustry::Healthcare->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $acme = Company::query()->where('name', 'Acme')->sole();

        expect($acme->industry)->toBe(CompanyIndustry::Healthcare)
            ->and($acme->getRawOriginal('industry'))->toBe('healthcare');

        Livewire::test(EditCompany::class, ['record' => $acme->id])
            ->fillForm(['industry' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($acme->fresh()->industry)->toBeNull();
    });

    it('offers the fixed list, searchable', function () {
        $fields = Livewire::test(CreateCompany::class)->instance()->getSchema('form')->getFlatFields();

        expect($fields['industry']->getOptions())->toBe(collect(CompanyIndustry::cases())->mapWithKeys(fn (CompanyIndustry $industry): array => [$industry->value => $industry->getLabel()])->all())
            ->and($fields['industry']->isSearchable())->toBeTrue()
            ->and($fields['industry']->isRequired())->toBeFalse();
    });

    it('refuses an unknown value', function () {
        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'Acme'])
            ->set('data.industry', 'space_mining')
            ->call('create')
            ->assertHasFormErrors(['industry']);

        expect(Company::count())->toBe(0);
    });
});

describe('the table', function () {
    it('filters on several industries', function () {
        $a = ($this->company)('A', CompanyIndustry::Software);
        $b = ($this->company)('B', CompanyIndustry::Healthcare);
        $c = ($this->company)('C');
        $d = ($this->company)('D', CompanyIndustry::Insurance);

        Livewire::test(ListCompanies::class)
            ->filterTable('industry', [CompanyIndustry::Software->value, CompanyIndustry::Healthcare->value])
            ->assertCanSeeTableRecords([$a, $b])
            ->assertCanNotSeeTableRecords([$c, $d]);
    });

    it('shows the label, a dash when there is none, sorts, and is shown by default', function () {
        $a = ($this->company)('A', CompanyIndustry::FinanceBanking);
        $c = ($this->company)('C');

        $page = Livewire::test(ListCompanies::class)
            ->assertTableColumnFormattedStateSet('industry', 'Finance & Banking', $a)
            ->sortTable('industry')
            ->assertCanSeeTableRecords([$a, $c]);

        $column = $page->instance()->getTable()->getColumn('industry');

        expect($column->record($c)->getPlaceholder())->toBe('—')
            ->and($column->isSortable())->toBeTrue()
            ->and($column->isToggleable())->toBeTrue()
            ->and($column->isToggledHiddenByDefault())->toBeFalse();
    });
});
