<?php

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
    $this->companyType = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Enterprise']);
});

test('create page renders', function () {
    $this->get(CompanyResource::getUrl('create'))->assertOk();
});

test('can create a company with type, address, notes and custom field values', function () {
    CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'name' => 'VAT Number',
        'type' => 'text',
        'unique' => true,
    ]);

    Livewire::test(CreateCompany::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'company_type_id' => $this->companyType->id,
            'notes' => 'Met at a conference.',
            'address.street' => '12 Rue de Rivoli',
            'address.city' => 'Paris',
            'address.zip' => '75001',
            'address.country' => 'France',
            'custom_field_values.vat_number' => 'FR123',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified()
        ->assertRedirect();

    $company = Company::query()->where('name', 'Acme Corp')->firstOrFail();

    expect($company->organization_id)->toBe($this->org->id)
        ->and($company->company_type_id)->toBe($this->companyType->id)
        ->and($company->notes)->toBe('Met at a conference.')
        ->and($company->custom_field_values)->toBe(['vat_number' => 'FR123'])
        ->and($company->address)->not->toBeNull()
        ->and($company->address->only(['organization_id', 'street', 'city', 'zip', 'country']))->toBe([
            'organization_id' => $this->org->id,
            'street' => '12 Rue de Rivoli',
            'city' => 'Paris',
            'zip' => '75001',
            'country' => 'France',
        ]);
});

test('does not create an address when the address fields are left empty', function () {
    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'No Address Inc'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Company::query()->where('name', 'No Address Inc')->value('address_id'))->toBeNull()
        ->and(Address::query()->count())->toBe(0);
});

test('a company can be created without a type', function () {
    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => 'Untyped'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Company::query()->where('name', 'Untyped')->value('company_type_id'))->toBeNull();
});

test('name is required', function () {
    Livewire::test(CreateCompany::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required'])
        ->assertNotNotified();
});

test('only shows the custom fields of the selected company type', function () {
    $startup = CompanyType::factory()->create(['organization_id' => $this->org->id]);
    $enterpriseField = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'name' => 'VAT Number',
    ]);
    $startupField = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $startup->id,
        'name' => 'Funding Stage',
    ]);

    Livewire::test(CreateCompany::class)
        ->assertFormFieldDoesNotExist("custom_field_values.{$enterpriseField->key}")
        ->fillForm(['company_type_id' => $this->companyType->id])
        ->assertFormFieldExists("custom_field_values.{$enterpriseField->key}")
        ->assertFormFieldDoesNotExist("custom_field_values.{$startupField->key}")
        ->fillForm(['company_type_id' => $startup->id])
        ->assertFormFieldExists("custom_field_values.{$startupField->key}")
        ->assertFormFieldDoesNotExist("custom_field_values.{$enterpriseField->key}");
});

test('only lists company types of the current organization', function () {
    $foreignType = CompanyType::factory()->create();

    Livewire::test(CreateCompany::class)
        ->assertFormFieldExists('company_type_id', fn ($field): bool => array_key_exists($this->companyType->id, $field->getOptions())
            && ! array_key_exists($foreignType->id, $field->getOptions()));
});

test('can create a company type inline from the type select', function () {
    $page = Livewire::test(CreateCompany::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('company_type_id'), data: ['name' => 'Partner'])
        ->assertHasNoActionErrors();

    $partner = CompanyType::query()->where('name', 'Partner')->firstOrFail();

    expect($partner->organization_id)->toBe($this->org->id);

    $page->assertFormSet(['company_type_id' => $partner->id]);
});

test('inline company type names must be unique in the organization', function () {
    Livewire::test(CreateCompany::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('company_type_id'), data: ['name' => 'Enterprise'])
        ->assertHasActionErrors(['name' => 'unique']);
});
