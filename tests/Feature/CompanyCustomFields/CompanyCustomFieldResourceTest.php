<?php

use App\Filament\Resources\CompanyCustomFields\CompanyCustomFieldResource;
use App\Filament\Resources\CompanyCustomFields\Pages\CreateCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\EditCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\ListCompanyCustomFields;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
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
    ]);

    $this->get(CompanyCustomFieldResource::getUrl('index'))->assertOk();
    $this->get(CompanyCustomFieldResource::getUrl('create'))->assertOk();
    $this->get(CompanyCustomFieldResource::getUrl('edit', ['record' => $field]))->assertOk();
});

test('list shows fields of the current organization only, in order', function () {
    $second = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'order' => 2,
    ]);
    $first = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'order' => 1,
    ]);
    $foreign = CompanyCustomField::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
    ]);

    Livewire::test(ListCompanyCustomFields::class)
        ->assertCanSeeTableRecords([$first, $second], inOrder: true)
        ->assertCanNotSeeTableRecords([$foreign]);
});

test('the table has no company type column or filter', function () {
    $table = Livewire::test(ListCompanyCustomFields::class)
        ->assertTableColumnDoesNotExist('companyType.name')
        ->instance()->getTable();

    expect(array_keys($table->getFilters()))->not->toContain('company_type_id');
});

test('the definition form has no company type', function () {
    Livewire::test(CreateCompanyCustomField::class)
        ->assertFormFieldDoesNotExist('company_type_id');
});

test('can create a company field', function () {
    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['name' => 'VAT Number', 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(CompanyCustomField::class, [
        'organization_id' => $this->org->id,
        'name' => 'VAT Number',
        'order' => 1,
    ]);
});

test('is stored separately from contact custom fields', function () {
    CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    expect(CompanyCustomField::count())->toBe(1)
        ->and(CustomField::count())->toBe(0);
});

test('create validates required fields and select options', function () {
    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['name' => null, 'type' => null])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'type' => 'required',
        ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm([
            'name' => 'Size',
            'type' => 'select',
            'options' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['options']);
});

test('names are unique per organization', function () {
    CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Region',
    ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['name' => 'Region', 'type' => 'url'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);
});

test('the same field name is allowed in another organization', function () {
    CompanyCustomField::factory()->create([
        'organization_id' => Organization::factory()->create()->id,
        'name' => 'VAT Number',
    ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['name' => 'VAT Number', 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();
});

test('a field keeps its own name valid when it is edited', function () {
    $field = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Region', 'type' => 'url']);

    Livewire::test(EditCompanyCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => 'Region'])
        ->call('save')
        ->assertHasNoFormErrors();
});

test('new fields go to the end of the organization list', function () {
    CompanyCustomField::factory()->count(2)->sequence(['order' => 1], ['order' => 2])->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['name' => 'Third', 'type' => 'text', 'position' => 'end'])
        ->call('create');

    expect(CompanyCustomField::where('name', 'Third')->value('order'))->toBe(3);
});

test('the next order is computed per organization when none is given', function () {
    $make = fn (int $organizationId, string $name) => CompanyCustomField::create(['organization_id' => $organizationId, 'name' => $name, 'type' => 'text']);
    $other = Organization::factory()->create();

    $first = $make($this->org->id, 'First');
    $foreign = $make($other->id, 'Foreign');
    $second = $make($this->org->id, 'Second');

    expect([$first->order, $foreign->order, $second->order])->toBe([1, 1, 2]);
});

test('can place a new field at the beginning', function () {
    $existing = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'order' => 1,
    ]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm([
            'name' => 'Top',
            'type' => 'text',
            'position' => 'beginning',
        ])
        ->call('create');

    expect(CompanyCustomField::where('name', 'Top')->value('order'))->toBe(1)
        ->and($existing->fresh()->order)->toBe(2);
});

test('can place a new field after another one, across the whole organization', function () {
    $industry = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Industry', 'order' => 1]);
    CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Website', 'order' => 2]);
    CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'VAT', 'order' => 3]);

    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['name' => 'Founded', 'type' => 'date', 'position' => "after_{$industry->id}"])
        ->call('create');

    expect(CompanyCustomField::orderBy('order')->pluck('name')->all())->toBe(['Industry', 'Founded', 'Website', 'VAT']);
});

test('the position options list the company fields of the organization', function () {
    $industry = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Industry']);
    CompanyCustomField::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Foreign']);

    Livewire::test(CreateCompanyCustomField::class)
        ->assertFormFieldExists('position', fn ($field): bool => array_keys($field->getOptions()) === ['beginning', 'end', "after_{$industry->id}"])
        ->assertSee('Where to place this field among the company fields');
});

test('can edit a field', function () {
    $field = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Old',
        'type' => 'text',
    ]);

    Livewire::test(EditCompanyCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => 'New'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($field->fresh()->name)->toBe('New');
});

test('can delete a field', function () {
    $field = CompanyCustomField::factory()->create([
        'organization_id' => $this->org->id,
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

test('company types cannot be force deleted', function () {
    expect(fn () => $this->companyType->forceDelete())->toThrow(LogicException::class);

    $this->assertNotSoftDeleted($this->companyType);
});

test('keys are unique per organization', function () {
    $field = CompanyCustomField::factory()->create(['organization_id' => $this->org->id]);
    $other = Organization::factory()->create();
    $row = fn (int $organizationId, string $name) => [
        'organization_id' => $organizationId, 'key' => $field->key, 'name' => $name, 'type' => 'text', 'unique' => false, 'order' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ];

    expect(fn () => DB::transaction(fn () => DB::table('company_custom_fields')->insert($row($this->org->id, 'Another name'))))->toThrow(QueryException::class)
        ->and(DB::table('company_custom_fields')->insert($row($other->id, 'Another name')))->toBeTrue();
});

test('the company_type_id column is gone', function () {
    expect(Schema::hasColumn('company_custom_fields', 'company_type_id'))->toBeFalse();
});

test('the menu lists contact fields then company fields in the Audience group', function () {
    expect(CustomFieldResource::getNavigationLabel())->toBe('Contact fields')
        ->and(CompanyCustomFieldResource::getNavigationLabel())->toBe('Company fields')
        ->and(CustomFieldResource::getNavigationGroup())->toBe('Audience')
        ->and(CompanyCustomFieldResource::getNavigationGroup())->toBe('Audience')
        ->and(CustomFieldResource::getNavigationSort())->toBe(10)
        ->and(CompanyCustomFieldResource::getNavigationSort())->toBe(11)
        ->and(CustomFieldResource::getModelLabel())->toBe('Contact field')
        ->and(CompanyCustomFieldResource::getPluralModelLabel())->toBe('Company fields');
});
