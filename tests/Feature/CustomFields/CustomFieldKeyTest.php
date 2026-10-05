<?php

use App\Filament\Resources\CompanyCustomFields\Pages\CreateCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\EditCompanyCustomField;
use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
    $this->companyType = CompanyType::factory()->create(['organization_id' => $this->org->id]);
});

test('key is a random string unrelated to the name', function (string $model) {
    $attributes = ['organization_id' => $this->org->id, 'name' => 'LinkedIn'];

    $first = $model::factory()->create($attributes);
    $second = $model::factory()->create([...$attributes, 'name' => 'Twitter']);

    expect($first->key)->toMatch('/^cf_[a-z0-9]{12}$/')
        ->and($first->key)->not->toContain('linkedin')
        ->and($second->key)->not->toBe($first->key);
})->with([
    'contact fields' => [CustomField::class],
    'company fields' => [CompanyCustomField::class],
]);

test('key generation retries when a generated key is already taken', function () {
    $taken = CustomField::factory()->create(['organization_id' => $this->org->id]);
    $field = CustomField::factory()->make(['organization_id' => $this->org->id]);

    Str::createRandomStringsUsingSequence([substr($taken->key, 3), 'abcdefghijkl']);

    expect($field->generateUniqueKey())->toBe('cf_abcdefghijkl');

    Str::createRandomStringsNormally();
});

test('key never changes when the field is renamed or the key is overwritten', function (string $model) {
    $attributes = ['organization_id' => $this->org->id, 'name' => 'Industry'];

    $field = $model::factory()->create($attributes);
    $originalKey = $field->key;

    $field->update(['name' => 'Business Sector']);
    $field->forceFill(['key' => 'hacked'])->save();

    expect($field->fresh()->key)->toBe($originalKey)
        ->and($field->fresh()->name)->toBe('Business Sector');
})->with([
    'contact fields' => [CustomField::class],
    'company fields' => [CompanyCustomField::class],
]);

test('key is generated when creating a contact custom field from the panel', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm(['name' => 'Preferred Language', 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CustomField::query()->where('name', 'Preferred Language')->value('key'))->toMatch('/^cf_[a-z0-9]{12}$/');
});

test('key is generated when creating a company custom field from the panel', function () {
    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['name' => 'VAT Number', 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CompanyCustomField::query()->where('name', 'VAT Number')->value('key'))->toMatch('/^cf_[a-z0-9]{12}$/');
});

test('edit forms show the key read-only and never save it', function (string $page, string $model) {
    $attributes = ['organization_id' => $this->org->id, 'name' => 'Industry', 'type' => 'text'];

    $field = $model::factory()->create($attributes);
    $originalKey = $field->key;

    Livewire::test($page, ['record' => $field->id])
        ->assertFormFieldIsDisabled('key')
        ->assertFormSet(['key' => $originalKey])
        ->fillForm(['name' => 'Sector', 'key' => 'sector'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($field->fresh()->key)->toBe($originalKey)
        ->and($field->fresh()->name)->toBe('Sector');
})->with([
    'contact fields' => [EditCustomField::class, CustomField::class],
    'company fields' => [EditCompanyCustomField::class, CompanyCustomField::class],
]);

test('formatValue renders stored values for display', function () {
    $field = CustomField::factory()->make([
        'type' => 'multiselect',
        'options' => [['label' => 'PHP / Laravel', 'value' => 'laravel'], ['label' => 'React', 'value' => 'react']],
    ]);
    $select = CustomField::factory()->make([
        'type' => 'select',
        'options' => [['label' => 'Signed', 'value' => 'signed']],
    ]);
    $text = CustomField::factory()->make(['type' => 'text']);

    expect($field->formatValue(['laravel', 'react', 'removed']))->toBe('PHP / Laravel, React, removed')
        ->and($select->formatValue('signed'))->toBe('Signed')
        ->and($select->formatValue('unknown'))->toBe('unknown')
        ->and($text->formatValue(150))->toBe('150')
        ->and($text->formatValue(null))->toBeNull()
        ->and($text->formatValue(''))->toBeNull()
        ->and($field->formatValue([]))->toBeNull();
});

test('whereCustomFieldValue matches scalar, numeric and array values', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'custom_field_values' => ['reference' => 'REF-1', 'employees' => 42, 'skills' => ['php', 'go']],
    ]);
    Contact::factory()->create([
        'organization_id' => $this->org->id,
        'custom_field_values' => ['reference' => 'REF-2', 'skills' => ['php']],
    ]);

    expect(Contact::query()->whereCustomFieldValue('reference', 'REF-1')->pluck('id')->all())->toBe([$contact->id])
        ->and(Contact::query()->whereCustomFieldValue('employees', '42')->pluck('id')->all())->toBe([$contact->id])
        ->and(Contact::query()->whereCustomFieldValue('skills', ['php', 'go'])->pluck('id')->all())->toBe([$contact->id])
        ->and(Contact::query()->whereCustomFieldValue('reference', 'missing')->exists())->toBeFalse();
});

test('custom field definitions resolve per owner', function () {
    $contactField = CustomField::factory()->create(['organization_id' => $this->org->id, 'order' => 1]);
    CustomField::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $companyField = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'order' => 1]);
    $secondCompanyField = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'order' => 2]);
    CompanyCustomField::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    CompanyCustomField::factory()->create(['organization_id' => $this->org->id])->delete();

    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $this->companyType->id]);
    $untypedCompany = Company::factory()->create(['organization_id' => $this->org->id]);

    expect($contact->customFieldDefinitions()->pluck('id')->all())->toBe([$contactField->id])
        ->and($company->customFieldDefinitions()->pluck('id')->all())->toBe([$companyField->id, $secondCompanyField->id])
        ->and($untypedCompany->customFieldDefinitions()->pluck('id')->all())->toBe([$companyField->id, $secondCompanyField->id]);
});

test('new contacts and companies default to empty custom field values', function () {
    $contact = Contact::create(['organization_id' => $this->org->id, 'name' => 'No Values', 'email' => 'none@example.com']);
    $company = Company::create(['organization_id' => $this->org->id, 'name' => 'No Values Inc']);

    expect($contact->fresh()->custom_field_values)->toBe([])
        ->and($company->fresh()->custom_field_values)->toBe([]);
});
