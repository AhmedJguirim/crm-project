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
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
    $this->companyType = CompanyType::factory()->create(['organization_id' => $this->org->id]);
});

test('key is generated from the name', function (string $name, string $expectedKey) {
    $field = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => $name]);

    expect($field->key)->toBe($expectedKey);
})->with([
    ['Job Title', 'job_title'],
    ['Hourly Rate (€)', 'hourly_rate_eur'],
    ['  LinkedIn  URL ', 'linkedin_url'],
    ['2024', 'field_2024'],
    ['!!!', 'field'],
]);

test('key gets a numeric suffix when already taken in the organization', function () {
    CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Job Title']);
    $second = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Job-Title']);
    $third = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'job title!']);

    expect($second->key)->toBe('job_title_2')
        ->and($third->key)->toBe('job_title_3');
});

test('contact field keys are unique per organization only', function () {
    $otherOrg = Organization::factory()->create();

    $mine = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Industry']);
    $theirs = CustomField::factory()->create(['organization_id' => $otherOrg->id, 'name' => 'Industry']);

    expect($mine->key)->toBe('industry')
        ->and($theirs->key)->toBe('industry');
});

test('company field keys are unique per company type', function () {
    $otherType = CompanyType::factory()->create(['organization_id' => $this->org->id]);

    $first = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $this->companyType->id, 'name' => 'Website']);
    $otherTypeField = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $otherType->id, 'name' => 'Website']);
    $sameTypeField = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $this->companyType->id, 'name' => 'Web site']);

    expect($first->key)->toBe('website')
        ->and($otherTypeField->key)->toBe('website')
        ->and($sameTypeField->key)->toBe('web_site');
});

test('key never changes when the field is renamed or the key is overwritten', function (string $model) {
    $attributes = ['organization_id' => $this->org->id, 'name' => 'Industry'];

    if ($model === CompanyCustomField::class) {
        $attributes['company_type_id'] = $this->companyType->id;
    }

    $field = $model::factory()->create($attributes);

    $field->update(['name' => 'Business Sector']);
    $field->forceFill(['key' => 'hacked'])->save();

    expect($field->fresh()->key)->toBe('industry')
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

    expect(CustomField::query()->where('name', 'Preferred Language')->value('key'))->toBe('preferred_language');
});

test('key is generated when creating a company custom field from the panel', function () {
    Livewire::test(CreateCompanyCustomField::class)
        ->fillForm(['company_type_id' => $this->companyType->id, 'name' => 'VAT Number', 'type' => 'text'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CompanyCustomField::query()->where('name', 'VAT Number')->value('key'))->toBe('vat_number');
});

test('edit forms show the key read-only and never save it', function (string $page, string $model) {
    $attributes = ['organization_id' => $this->org->id, 'name' => 'Industry', 'type' => 'text'];

    if ($model === CompanyCustomField::class) {
        $attributes['company_type_id'] = $this->companyType->id;
    }

    $field = $model::factory()->create($attributes);

    Livewire::test($page, ['record' => $field->id])
        ->assertFormFieldIsDisabled('key')
        ->assertFormSet(['key' => 'industry'])
        ->fillForm(['name' => 'Sector', 'key' => 'sector'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($field->fresh()->key)->toBe('industry')
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
    $companyField = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $this->companyType->id]);
    CompanyCustomField::factory()->create(['organization_id' => $this->org->id]);

    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'company_type_id' => $this->companyType->id]);
    $untypedCompany = Company::factory()->create(['organization_id' => $this->org->id]);

    expect($contact->customFieldDefinitions()->pluck('id')->all())->toBe([$contactField->id])
        ->and($company->customFieldDefinitions()->pluck('id')->all())->toBe([$companyField->id])
        ->and($untypedCompany->customFieldDefinitions())->toBeEmpty();
});

test('new contacts and companies default to empty custom field values', function () {
    $contact = Contact::create(['organization_id' => $this->org->id, 'name' => 'No Values', 'email' => 'none@example.com']);
    $company = Company::create(['organization_id' => $this->org->id, 'name' => 'No Values Inc']);

    expect($contact->fresh()->custom_field_values)->toBe([])
        ->and($company->fresh()->custom_field_values)->toBe([]);
});
