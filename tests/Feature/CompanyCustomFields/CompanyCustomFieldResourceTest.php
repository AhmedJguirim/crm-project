<?php

use App\Filament\Resources\CompanyCustomFields\CompanyCustomFieldResource;
use App\Filament\Resources\CompanyCustomFields\Pages\CreateCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\EditCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\ListCompanyCustomFields;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
    $this->companyType = CompanyType::factory()->create(['organization_id' => $this->org->id]);
});

test('index, create and edit pages render', function () {
    $field = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
    ]);

    $this->get(CompanyCustomFieldResource::getUrl('index'))->assertOk();
    $this->get(CompanyCustomFieldResource::getUrl('create'))->assertOk();
    $this->get(CompanyCustomFieldResource::getUrl('edit', ['record' => $field]))->assertOk();
});

test('list shows fields of the current organization only, in order', function () {
    $second = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'order' => 2,
    ]);
    $first = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'order' => 1,
    ]);
    $foreign = CompanyCustomField::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
    ]);

    Livewire::test(ListCompanyCustomFields::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertCanNotSeeTableRecords([$foreign]);
});

test('list can be filtered by company type', function () {
    $otherType = CompanyType::factory()->create(['organization_id' => $this->org->id]);
    $inType = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
    ]);
    $inOtherType = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $otherType->id,
    ]);

    Livewire::test(ListCompanyCustomFields::class)
        ->filterTable('company_type_id', $this->companyType->id)
        ->assertCanSeeTableRecords([$inType])
        ->assertCanNotSeeTableRecords([$inOtherType]);
});

test('can create a field tied to a company type', function () {
    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm([
            'company_type_id' => $this->companyType->id,
            'name' => 'VAT Number',
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(CompanyCustomField::class, [
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'name' => 'VAT Number',
        'order' => 1,
    ]);
});

test('is stored separately from contact custom fields', function () {
    CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
    ]);

    expect(CompanyCustomField::count())->toBe(1)
        ->and(CustomField::count())->toBe(0);
});

test('create validates required fields and select options', function () {
    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['company_type_id' => null, 'name' => null, 'type' => null])
        ->call('create')
        ->assertHasFormErrors([
            'company_type_id' => 'required',
            'name' => 'required',
            'type' => 'required',
        ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm([
            'company_type_id' => $this->companyType->id,
            'name' => 'Size',
            'type' => 'select',
            'options' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['options']);
});

test('name is unique per company type only', function () {
    $otherType = CompanyType::factory()->create(['organization_id' => $this->org->id]);
    CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'name' => 'Industry',
    ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['company_type_id' => $this->companyType->id, 'name' => 'Industry', 'type' => 'text'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['company_type_id' => $otherType->id, 'name' => 'Industry', 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();
});

test('new fields are ordered within their company type', function () {
    $otherType = CompanyType::factory()->create(['organization_id' => $this->org->id]);
    CompanyCustomField::factory()->count(2)->sequence(['order' => 1], ['order' => 2])->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $otherType->id,
    ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['company_type_id' => $this->companyType->id, 'name' => 'First', 'type' => 'text'])
        ->call('create');

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['company_type_id' => $this->companyType->id, 'name' => 'Second', 'type' => 'text'])
        ->call('create');

    expect(CompanyCustomField::where('name', 'First')->value('order'))->toBe(1)
        ->and(CompanyCustomField::where('name', 'Second')->value('order'))->toBe(2);
});

test('can place a new field at the beginning', function () {
    $existing = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'order' => 1,
    ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm([
            'company_type_id' => $this->companyType->id,
            'name' => 'Top',
            'type' => 'text',
            'position' => 'beginning',
        ])
        ->call('create');

    expect(CompanyCustomField::where('name', 'Top')->value('order'))->toBe(1)
        ->and($existing->fresh()->order)->toBe(2);
});

test('can edit a field but not change its company type', function () {
    $otherType = CompanyType::factory()->create(['organization_id' => $this->org->id]);
    $field = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'name' => 'Old',
        'type' => 'text',
    ]);

    Livewire::test(EditCompanyCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => 'New', 'company_type_id' => $otherType->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($field->fresh()->name)->toBe('New')
        ->and($field->fresh()->company_type_id)->toBe($this->companyType->id);
});

test('can delete a field', function () {
    $field = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
    ]);

    Livewire::test(EditCompanyCustomField::class, ['record' => $field->id])
        ->callAction(DeleteAction::class);

    $this->assertSoftDeleted($field);
});

test('cannot open a field from another organization', function () {
    $foreign = CompanyCustomField::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
    ]);

    $this->get(CompanyCustomFieldResource::getUrl('edit', ['record' => $foreign->id]))
        ->assertNotFound();
});

test('deleting a company type cascades to its custom fields', function () {
    CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
    ]);

    $this->companyType->forceDelete();

    expect(CompanyCustomField::withoutGlobalScopes()->count())->toBe(0);
});

test('company type exposes its custom fields in order', function () {
    $second = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'order' => 2,
    ]);
    $first = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'company_type_id' => $this->companyType->id,
        'order' => 1,
    ]);

    expect($this->companyType->customFields->pluck('id')->all())->toBe([$first->id, $second->id]);
});

test('the same field name is allowed in another organization', function () {
    $otherOrg = Organization::factory()->create();
    $otherType = CompanyType::factory()->create(['organization_id' => $otherOrg->id]);
    CompanyCustomField::factory()->create([
        'organization_id' => $otherOrg->id,
        'company_type_id' => $otherType->id,
        'name' => 'VAT Number',
    ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['company_type_id' => $this->companyType->id, 'name' => 'VAT Number', 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();
});

test('cannot create a field for a company type of another organization', function () {
    $otherType = CompanyType::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['company_type_id' => $otherType->id, 'name' => 'VAT Number', 'type' => 'text'])
        ->call('create')
        ->assertHasFormErrors(['company_type_id']);

    expect(CompanyCustomField::query()->withoutGlobalScopes()->where('company_type_id', $otherType->id)->exists())->toBeFalse();
});
