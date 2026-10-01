<?php

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The contact and company forms share the same custom field values handler,
 * so every behaviour below is asserted against both forms.
 */
function customFieldValuesOwner(string $owner, Organization $organization): object
{
    return match ($owner) {
        'contact' => new class($organization)
        {
            public string $createPage = CreateContact::class;

            public string $editPage = EditContact::class;

            public function __construct(public Organization $organization) {}

            public function field(array $attributes = []): CustomField
            {
                return CustomField::factory()->create([
                    'organization_id' => $this->organization->id,
                    'type' => 'text',
                    'unique' => false,
                    ...$attributes,
                ]);
            }

            public function record(array $values = [], ?Organization $organization = null): Contact
            {
                return Contact::factory()->create([
                    'organization_id' => ($organization ?? $this->organization)->id,
                    'custom_field_values' => $values,
                ]);
            }

            public function formData(): array
            {
                return ['name' => 'Jane Doe', 'email' => fake()->unique()->safeEmail()];
            }

            public function latestRecord(): Contact
            {
                return Contact::query()->latest('id')->firstOrFail();
            }
        },
        'company' => new class($organization)
        {
            public string $createPage = CreateCompany::class;

            public string $editPage = EditCompany::class;

            public CompanyType $companyType;

            public function __construct(public Organization $organization)
            {
                $this->companyType = CompanyType::factory()->create(['organization_id' => $organization->id]);
            }

            public function field(array $attributes = []): CompanyCustomField
            {
                return CompanyCustomField::factory()->create([
                    'organization_id' => $this->organization->id,
                    'company_type_id' => $this->companyType->id,
                    'type' => 'text',
                    'unique' => false,
                    ...$attributes,
                ]);
            }

            public function record(array $values = [], ?Organization $organization = null): Company
            {
                $organization ??= $this->organization;

                return Company::factory()->create([
                    'organization_id' => $organization->id,
                    'company_type_id' => $organization->is($this->organization)
                        ? $this->companyType->id
                        : CompanyType::factory()->create(['organization_id' => $organization->id])->id,
                    'custom_field_values' => $values,
                ]);
            }

            public function formData(): array
            {
                return ['name' => 'Acme Corp', 'company_type_id' => $this->companyType->id];
            }

            public function latestRecord(): Company
            {
                return Company::query()->latest('id')->firstOrFail();
            }
        },
    };
}

/** @param  array<string, mixed>  $values */
function customFieldValuesFormState(array $values): array
{
    return collect($values)->mapWithKeys(fn (mixed $value, string $key): array => ["custom_field_values.{$key}" => $value])->all();
}

/** @param  array<int, string>  $keys */
function addCustomFieldsToForm(Testable $page, array $keys): Testable
{
    foreach ($keys as $key) {
        $page->set('data.custom_field_picker', $key);
    }

    return $page;
}

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

dataset('owners', ['contact', 'company']);

test('renders a matching input for every custom field type', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);

    $expectedComponents = [
        'text' => TextInput::class,
        'email' => TextInput::class,
        'url' => TextInput::class,
        'phone' => TextInput::class,
        'number' => TextInput::class,
        'textarea' => Textarea::class,
        'date' => DatePicker::class,
        'select' => Select::class,
        'multiselect' => Select::class,
    ];

    $fields = collect($expectedComponents)->map(fn (string $component, string $type) => $owner->field([
        'name' => "My {$type} field",
        'type' => $type,
        'options' => in_array($type, ['select', 'multiselect']) ? [['label' => 'One', 'value' => 'one']] : null,
    ]));

    $page = addCustomFieldsToForm(Livewire::test($owner->createPage)->fillForm($owner->formData()), $fields->pluck('key')->all())
        ->fillForm($owner->formData());

    foreach ($fields as $type => $field) {
        $page->assertFormFieldExists(
            "custom_field_values.{$field->key}",
            fn (Field $component): bool => $component instanceof $expectedComponents[$type]
                && $component->getLabel() === "My {$type} field",
        );
    }
})->with('owners');

test('hides the custom fields section when there are no custom fields', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);

    Livewire::test($owner->createPage)
        ->fillForm($owner->formData())
        ->assertDontSee('Additional Information');
})->with('owners');

test('stores values under the immutable field key on create', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $industry = $owner->field(['name' => 'Industry']);
    $skills = $owner->field([
        'name' => 'Skills',
        'type' => 'multiselect',
        'options' => [['label' => 'PHP', 'value' => 'php'], ['label' => 'Go', 'value' => 'go']],
    ]);

    addCustomFieldsToForm(Livewire::test($owner->createPage)->fillForm($owner->formData()), [$industry->key, $skills->key])
        ->fillForm([
            ...$owner->formData(),
            ...customFieldValuesFormState([$industry->key => 'Software', $skills->key => ['php', 'go']]),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect($owner->latestRecord()->custom_field_values)->toEqual([$industry->key => 'Software', $skills->key => ['php', 'go']]);
})->with('owners');

test('fills the edit form with the stored values', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Industry']);
    $record = $owner->record([$field->key => 'Software']);

    Livewire::test($owner->editPage, ['record' => $record->getKey()])
        ->assertFormSet(customFieldValuesFormState([$field->key => 'Software']));
})->with('owners');

test('updates values on edit and keeps stored values of fields not in the form', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Industry']);
    $record = $owner->record([$field->key => 'Software', 'legacy_field' => 'kept']);

    Livewire::test($owner->editPage, ['record' => $record->getKey()])
        ->fillForm(customFieldValuesFormState([$field->key => 'Finance']))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->custom_field_values)->toEqual([$field->key => 'Finance', 'legacy_field' => 'kept']);
})->with('owners');

test('edit form only renders fields holding a value', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $used = $owner->field(['name' => 'Industry']);
    $unused = $owner->field(['name' => 'Region']);
    $record = $owner->record([$used->key => 'Software']);

    Livewire::test($owner->editPage, ['record' => $record->getKey()])
        ->assertFormFieldExists("custom_field_values.{$used->key}")
        ->assertFormFieldDoesNotExist("custom_field_values.{$unused->key}");
})->with('owners');

test('the picker only offers fields that are not displayed yet', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $used = $owner->field(['name' => 'Industry']);
    $unused = $owner->field(['name' => 'Region']);
    $record = $owner->record([$used->key => 'Software']);

    Livewire::test($owner->editPage, ['record' => $record->getKey()])
        ->assertFormFieldExists('custom_field_picker', fn (Select $picker): bool => $picker->getOptions() === [$unused->key => 'Region']);
})->with('owners');

test('adding a field from the picker displays it empty', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $first = $owner->field(['name' => 'Industry', 'order' => 1]);
    $second = $owner->field(['name' => 'Region', 'order' => 2]);
    $record = $owner->record([$second->key => 'EMEA']);

    $page = Livewire::test($owner->editPage, ['record' => $record->getKey()]);
    addCustomFieldsToForm($page, [$first->key]);

    $page->assertFormFieldExists("custom_field_values.{$first->key}")
        ->assertFormSet(['custom_field_picker' => null]);
})->with('owners');

test('a multiselect added from the picker starts as an empty array and saves its selection', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field([
        'name' => 'Skills',
        'type' => 'multiselect',
        'options' => [['label' => 'PHP', 'value' => 'php'], ['label' => 'Go', 'value' => 'go']],
    ]);
    $record = $owner->record();

    $page = Livewire::test($owner->editPage, ['record' => $record->getKey()]);
    addCustomFieldsToForm($page, [$field->key])
        ->assertSet("data.custom_field_values.{$field->key}", [])
        ->fillForm(customFieldValuesFormState([$field->key => ['php', 'go']]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->custom_field_values)->toEqual([$field->key => ['php', 'go']]);
})->with('owners');

test('a field added but left empty is not stored', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $used = $owner->field(['name' => 'Industry']);
    $added = $owner->field(['name' => 'Region']);
    $record = $owner->record([$used->key => 'Software']);

    $page = Livewire::test($owner->editPage, ['record' => $record->getKey()]);
    addCustomFieldsToForm($page, [$added->key])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->custom_field_values)->toEqual([$used->key => 'Software']);
})->with('owners');

test('a field added and filled is stored under its key', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $used = $owner->field(['name' => 'Industry']);
    $added = $owner->field(['name' => 'Region']);
    $record = $owner->record([$used->key => 'Software']);

    $page = Livewire::test($owner->editPage, ['record' => $record->getKey()]);
    addCustomFieldsToForm($page, [$added->key])
        ->fillForm(customFieldValuesFormState([$added->key => 'EMEA']))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->custom_field_values)->toEqual([$used->key => 'Software', $added->key => 'EMEA']);
})->with('owners');

test('removing a field deletes its value from the record', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $removed = $owner->field(['name' => 'Industry']);
    $kept = $owner->field(['name' => 'Region']);
    $record = $owner->record([$removed->key => 'Software', $kept->key => 'EMEA']);

    Livewire::test($owner->editPage, ['record' => $record->getKey()])
        ->callFormComponentAction("custom_field_values.{$removed->key}", "remove_{$removed->key}")
        ->assertFormSet(['active_custom_field_keys' => [$kept->key]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->custom_field_values)->toEqual([$kept->key => 'EMEA']);
})->with('owners');

test('a removed field can be added back empty', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Industry']);
    $record = $owner->record([$field->key => 'Software']);

    $page = Livewire::test($owner->editPage, ['record' => $record->getKey()])
        ->callFormComponentAction("custom_field_values.{$field->key}", "remove_{$field->key}");
    addCustomFieldsToForm($page, [$field->key])
        ->assertFormFieldExists("custom_field_values.{$field->key}")
        ->assertFormSet(customFieldValuesFormState([$field->key => null]));
})->with('owners');

test('clearing a displayed value removes the key', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Industry']);
    $record = $owner->record([$field->key => 'Software']);

    Livewire::test($owner->editPage, ['record' => $record->getKey()])
        ->fillForm(customFieldValuesFormState([$field->key => null]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect($record->fresh()->custom_field_values)->toBe([]);
})->with('owners');

test('renaming a field keeps its stored values attached', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Industry']);
    $record = $owner->record([$field->key => 'Software']);

    $field->update(['name' => 'Business Sector']);

    Livewire::test($owner->editPage, ['record' => $record->getKey()])
        ->assertFormFieldExists("custom_field_values.{$field->key}", fn (Field $component): bool => $component->getLabel() === 'Business Sector')
        ->assertFormSet(customFieldValuesFormState([$field->key => 'Software']));
})->with('owners');

test('validates values against the field type', function (string $owner, string $type, mixed $invalidValue, string $errorPath) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field([
        'name' => 'Checked',
        'type' => $type,
        'options' => [['label' => 'One', 'value' => 'one']],
    ]);

    addCustomFieldsToForm(Livewire::test($owner->createPage)->fillForm($owner->formData()), [$field->key])
        ->fillForm([...$owner->formData(), ...customFieldValuesFormState([$field->key => $invalidValue])])
        ->call('create')
        ->assertHasFormErrors(["custom_field_values.{$field->key}{$errorPath}"]);
})->with('owners')->with([
    'email' => ['email', 'not-an-email', ''],
    'url' => ['url', 'not a url', ''],
    'number' => ['number', 'abc', ''],
    'select' => ['select', 'unknown-option', ''],
    'multiselect' => ['multiselect', ['unknown-option'], '.0'],
]);

test('unique fields reject a value used by another record of the organization', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Reference', 'unique' => true]);
    $owner->record([$field->key => 'REF-001']);

    addCustomFieldsToForm(Livewire::test($owner->createPage)->fillForm($owner->formData()), [$field->key])
        ->fillForm([...$owner->formData(), ...customFieldValuesFormState([$field->key => 'REF-001'])])
        ->call('create')
        ->assertHasFormErrors(["custom_field_values.{$field->key}"]);
})->with('owners');

test('unique fields compare numbers stored as numbers with submitted strings', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Employee Number', 'type' => 'number', 'unique' => true]);
    $owner->record([$field->key => 42]);

    addCustomFieldsToForm(Livewire::test($owner->createPage)->fillForm($owner->formData()), [$field->key])
        ->fillForm([...$owner->formData(), ...customFieldValuesFormState([$field->key => '42'])])
        ->call('create')
        ->assertHasFormErrors(["custom_field_values.{$field->key}"]);
})->with('owners');

test('unique fields accept the record keeping its own value', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Reference', 'unique' => true]);
    $record = $owner->record([$field->key => 'REF-001']);

    Livewire::test($owner->editPage, ['record' => $record->getKey()])
        ->fillForm(customFieldValuesFormState([$field->key => 'REF-001']))
        ->call('save')
        ->assertHasNoFormErrors();
})->with('owners');

test('unique fields accept a value used in another organization', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Reference', 'unique' => true]);
    $owner->record([$field->key => 'REF-001'], Organization::factory()->create());

    addCustomFieldsToForm(Livewire::test($owner->createPage)->fillForm($owner->formData()), [$field->key])
        ->fillForm([...$owner->formData(), ...customFieldValuesFormState([$field->key => 'REF-001'])])
        ->call('create')
        ->assertHasNoFormErrors();
})->with('owners');

test('non unique fields accept duplicated values', function (string $owner) {
    $owner = customFieldValuesOwner($owner, $this->org);
    $field = $owner->field(['name' => 'Industry']);
    $owner->record([$field->key => 'Software']);

    addCustomFieldsToForm(Livewire::test($owner->createPage)->fillForm($owner->formData()), [$field->key])
        ->fillForm([...$owner->formData(), ...customFieldValuesFormState([$field->key => 'Software'])])
        ->call('create')
        ->assertHasNoFormErrors();
})->with('owners');
