<?php

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\RelationManagers\ContactsRelationManager;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
    $this->companyType = CompanyType::factory()->create(['organization_id' => $this->org->id]);
    $this->company = Company::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'name' => 'Acme Corp',
        'address_id' => Address::factory()->create(['organization_id' => $this->org->id, 'city' => 'Paris'])->id,
    ]);
});

test('edit page renders with the contacts relation manager', function () {
    $this->get(CompanyResource::getUrl('edit', ['record' => $this->company]))->assertOk();

    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->assertSeeLivewire(ContactsRelationManager::class);
});

test('fills the form with the company and its address', function () {
    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->assertFormSet([
            'name' => 'Acme Corp',
            'company_type_id' => $this->companyType->id,
            'address.city' => 'Paris',
        ]);
});

test('can update the company and its address', function () {
    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->fillForm([
            'name' => 'Acme International',
            'address.city' => 'Lyon',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $this->company->refresh();

    expect($this->company->name)->toBe('Acme International')
        ->and($this->company->address->city)->toBe('Lyon')
        ->and(Address::query()->count())->toBe(1);
});

test('adds an address to a company that had none', function () {
    $company = Company::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(EditCompany::class, ['record' => $company->id])
        ->fillForm(['address.city' => 'Berlin', 'address.country' => 'Germany'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()->address->only(['city', 'country', 'organization_id']))
        ->toBe(['city' => 'Berlin', 'country' => 'Germany', 'organization_id' => $this->org->id]);
});

test('changing the type shows the new type fields and keeps values of the previous type', function () {
    $vatField = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'name' => 'VAT Number',
        'type' => 'text',
        'unique' => false,
    ]);
    $startup = CompanyType::factory()->create(['organization_id' => $this->org->id]);
    $fundingField = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $startup->id,
        'name' => 'Funding Stage',
        'type' => 'text',
        'unique' => false,
    ]);
    $this->company->update(['custom_field_values' => [$vatField->key => 'FR123']]);

    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->fillForm(['company_type_id' => $startup->id])
        ->set('data.custom_field_picker', $fundingField->key)
        ->assertFormFieldExists("custom_field_values.{$fundingField->key}")
        ->assertFormFieldDoesNotExist("custom_field_values.{$vatField->key}")
        ->fillForm(["custom_field_values.{$fundingField->key}" => 'Seed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->company->fresh()->company_type_id)->toBe($startup->id)
        ->and($this->company->fresh()->custom_field_values)->toEqual([
            $vatField->key => 'FR123',
            $fundingField->key => 'Seed',
        ]);
});

test('can soft delete, restore and force delete a company', function () {
    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->callAction(DeleteAction::class)
        ->assertRedirect();

    $this->assertSoftDeleted($this->company);

    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->assertActionHidden(DeleteAction::class)
        ->callAction(RestoreAction::class);

    $this->assertNotSoftDeleted($this->company);
});

test('companies cannot be force deleted, only restored once trashed', function () {
    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->assertActionDoesNotExist(ForceDeleteAction::class)
        ->assertActionHidden(RestoreAction::class);

    $this->company->delete();

    Livewire::test(EditCompany::class, ['record' => $this->company->id])
        ->assertActionDoesNotExist(ForceDeleteAction::class)
        ->assertActionVisible(RestoreAction::class);

    $this->assertSoftDeleted($this->company);
});

test('cannot open a company from another organization', function () {
    $foreign = Company::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->get(CompanyResource::getUrl('edit', ['record' => $foreign->id]))->assertNotFound();
});
