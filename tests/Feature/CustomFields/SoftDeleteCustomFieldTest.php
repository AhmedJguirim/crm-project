<?php

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\CompanyCustomFields\Pages\CreateCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\EditCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\ListCompanyCustomFields;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Contact and company custom fields are soft deleted the same way, so every behaviour
 * below is asserted against both.
 */
function softDeleteOwner(string $owner, object $test): object
{
    $organization = $test->org;
    $companyType = CompanyType::factory()->create(['organization_id' => $organization->id]);

    return match ($owner) {
        'contact' => new class($organization)
        {
            public string $createFieldPage = CreateCustomField::class;

            public string $editFieldPage = EditCustomField::class;

            public string $listPage = ListCustomFields::class;

            public string $editRecordPage = EditContact::class;

            public string $recordsLabel = 'contacts';

            public function __construct(public $organization) {}

            public function formData(string $name): array
            {
                return ['name' => $name, 'type' => 'text'];
            }

            public function field(array $attributes = []): CustomField
            {
                return CustomField::factory()->create([
                    'organization_id' => $this->organization->id,
                    'type' => 'text',
                    ...$attributes,
                ]);
            }

            public function record(array $values): Contact
            {
                return Contact::factory()->create([
                    'organization_id' => $this->organization->id,
                    'custom_field_values' => $values,
                ]);
            }
        },
        'company' => new class($organization, $companyType)
        {
            public string $createFieldPage = CreateCompanyCustomField::class;

            public string $editFieldPage = EditCompanyCustomField::class;

            public string $listPage = ListCompanyCustomFields::class;

            public string $editRecordPage = EditCompany::class;

            public string $recordsLabel = 'companies';

            public function __construct(public $organization, public CompanyType $companyType) {}

            public function formData(string $name): array
            {
                return ['name' => $name, 'type' => 'text', 'company_type_id' => $this->companyType->id];
            }

            public function field(array $attributes = []): CompanyCustomField
            {
                return CompanyCustomField::factory()->create([
                    'organization_id' => $this->organization->id,
                    'company_type_id' => $this->companyType->id,
                    'type' => 'text',
                    ...$attributes,
                ]);
            }

            public function record(array $values): Company
            {
                return Company::factory()->create([
                    'organization_id' => $this->organization->id,
                    'company_type_id' => $this->companyType->id,
                    'custom_field_values' => $values,
                ]);
            }
        },
    };
}

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('deleting a field soft deletes it from the edit page', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $field = $owner->field();

    Livewire::test($owner->editFieldPage, ['record' => $field->id])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    $this->assertSoftDeleted($field);
})->with(['contact', 'company']);

test('the delete confirmation reassures that the data is kept and comes back on restore', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $field = $owner->field(['name' => 'LinkedIn']);

    $page = Livewire::test($owner->editFieldPage, ['record' => $field->id])
        ->mountAction(DeleteAction::class)
        ->assertActionMounted(DeleteAction::class);
    $action = $page->instance()->getMountedAction();

    expect($action->getModalHeading())->toBe('Delete the "LinkedIn" field?')
        ->and($action->getModalDescription())
        ->toContain("hidden from your {$owner->recordsLabel}")
        ->toContain('No data is lost')
        ->toContain('restore it at any time')
        ->toContain('reappear exactly as it was');
})->with(['contact', 'company']);

test('the bulk delete confirmation reassures that the data is kept', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $fields = collect([$owner->field(['name' => 'LinkedIn']), $owner->field(['name' => 'Region'])]);

    $page = Livewire::test($owner->listPage)
        ->selectTableRecords($fields)
        ->mountAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());
    $action = $page->instance()->getMountedAction();

    expect($action->getModalHeading())->toBe('Delete the selected fields?')
        ->and($action->getModalDescription())
        ->toContain('No data is lost')
        ->toContain('reappear exactly as it was');
})->with(['contact', 'company']);

test('deleting a field keeps the values stored on the records', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $field = $owner->field(['name' => 'LinkedIn']);
    $record = $owner->record([$field->key => 'https://linkedin.com/in/jane']);

    Livewire::test($owner->editFieldPage, ['record' => $field->id])
        ->callAction(DeleteAction::class);

    expect($record->fresh()->custom_field_values)->toEqual([$field->key => 'https://linkedin.com/in/jane']);
})->with(['contact', 'company']);

test('a deleted field disappears from the record forms and definitions', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $field = $owner->field(['name' => 'LinkedIn']);
    $record = $owner->record([$field->key => 'https://linkedin.com/in/jane']);

    $field->delete();

    expect($record->customFieldDefinitions())->toBeEmpty();

    Livewire::test($owner->editRecordPage, ['record' => $record->getKey()])
        ->assertFormFieldDoesNotExist("custom_field_values.{$field->key}");
})->with(['contact', 'company']);

test('saving a record keeps the values of deleted fields', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $deleted = $owner->field(['name' => 'LinkedIn']);
    $kept = $owner->field(['name' => 'Region']);
    $record = $owner->record([$deleted->key => 'https://linkedin.com/in/jane', $kept->key => 'EMEA']);

    $deleted->delete();

    Livewire::test($owner->editRecordPage, ['record' => $record->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->custom_field_values)->toEqual([
        $deleted->key => 'https://linkedin.com/in/jane',
        $kept->key => 'EMEA',
    ]);
})->with(['contact', 'company']);

test('restoring a field brings its data back on the records', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $field = $owner->field(['name' => 'LinkedIn']);
    $record = $owner->record([$field->key => 'https://linkedin.com/in/jane']);

    $field->delete();

    Livewire::test($owner->editFieldPage, ['record' => $field->id])
        ->assertActionHidden(DeleteAction::class)
        ->callAction(RestoreAction::class)
        ->assertNotified();

    $this->assertNotSoftDeleted($field);

    Livewire::test($owner->editRecordPage, ['record' => $record->getKey()])
        ->assertFormSet(["custom_field_values.{$field->key}" => 'https://linkedin.com/in/jane']);
})->with(['contact', 'company']);

test('trashed fields are hidden from the list until the trashed filter is used', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $active = $owner->field(['name' => 'Region']);
    $trashed = $owner->field(['name' => 'LinkedIn']);
    $trashed->delete();

    Livewire::test($owner->listPage)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$trashed])
        ->filterTable('trashed', true)
        ->assertCanSeeTableRecords([$active, $trashed]);
})->with(['contact', 'company']);

test('fields can be restored in bulk from the list', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $fields = collect([$owner->field(['name' => 'LinkedIn']), $owner->field(['name' => 'Region'])]);
    $fields->each->delete();

    Livewire::test($owner->listPage)
        ->filterTable('trashed', true)
        ->selectTableRecords($fields)
        ->callAction(TestAction::make(RestoreBulkAction::class)->table()->bulk())
        ->assertNotified();

    $fields->each(fn ($field) => $this->assertNotSoftDeleted($field));
})->with(['contact', 'company']);

test('a deleted field keeps its key reserved', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $field = $owner->field(['name' => 'LinkedIn']);
    $field->delete();

    expect($field->generateUniqueKey())->not->toBe($field->key);
})->with(['contact', 'company']);

test('a name taken by a deleted field invites the user to check the trashed fields', function (string $owner) {
    $owner = softDeleteOwner($owner, $this);
    $owner->field(['name' => 'LinkedIn'])->delete();

    Livewire::test($owner->createFieldPage)
        ->fillForm($owner->formData('LinkedIn'))
        ->call('create')
        ->assertHasFormErrors(['name' => 'unique']);

    expect(Livewire::test($owner->createFieldPage)->fillForm($owner->formData('LinkedIn'))->call('create')->errors()->first('data.name'))
        ->toContain('"Trashed" filter')
        ->toContain('restore it');
})->with(['contact', 'company']);
