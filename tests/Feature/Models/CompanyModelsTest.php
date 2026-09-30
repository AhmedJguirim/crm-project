<?php

use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;

it('soft deletes companies, company types and addresses', function (): void {
    $company = Company::factory()->create();
    $type = CompanyType::factory()->create();
    $address = Address::factory()->create();

    $company->delete();
    $type->delete();
    $address->delete();

    expect(Company::withTrashed()->find($company->id))->not->toBeNull()
        ->and(Company::find($company->id))->toBeNull()
        ->and(CompanyType::find($type->id))->toBeNull()
        ->and(Address::find($address->id))->toBeNull();
});

it('links company to address, type and organization', function (): void {
    $company = Company::factory()->create([
        'address_id' => Address::factory(),
        'company_type_id' => CompanyType::factory(),
        'custom_field_values' => ['1' => 'x'],
    ]);

    expect($company->address)->toBeInstanceOf(Address::class)
        ->and($company->companyType->companies)->toHaveCount(1)
        ->and($company->organization->companies)->toHaveCount(1)
        ->and($company->custom_field_values)->toBe(['1' => 'x']);
});

it('scopes tenant models to the current filament tenant and fills organization_id', function (): void {
    $this->actingAs(User::factory()->create());

    $organization = Organization::factory()->create();
    $other = Organization::factory()->create();

    $mine = Company::factory()->for($organization)->create();
    $theirs = Company::factory()->for($other)->create();

    Filament::setTenant($organization);

    $created = CompanyType::create(['name' => 'Client']);

    expect(Company::pluck('id')->all())->toBe([$mine->id])
        ->and(Company::withoutGlobalScope('organization')->count())->toBe(2)
        ->and($created->organization_id)->toBe($organization->id);

    Filament::setTenant(null);
});
