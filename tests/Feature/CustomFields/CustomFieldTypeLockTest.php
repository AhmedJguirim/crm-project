<?php

use App\Exceptions\CustomFieldTypeLockedException;
use App\Filament\Resources\CompanyCustomFields\Pages\CreateCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\EditCompanyCustomField;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
    $this->companyType = CompanyType::factory()->create(['organization_id' => $this->org->id]);

    $this->textField = fn (string $model, array $attributes = []): CustomField|CompanyCustomField => $model === 'contact'
        ? CustomField::factory()->for($this->org)->text()->create(['name' => 'Ref', ...$attributes])
        : CompanyCustomField::factory()->for($this->org)->create(['type' => 'text', 'name' => 'Ref', ...$attributes]);

    $this->editPage = fn (string $model, CustomField|CompanyCustomField $field) => Livewire::test(
        $model === 'contact' ? EditCustomField::class : EditCompanyCustomField::class,
        ['record' => $field->getRouteKey()],
    );
});

it('lets the type be chosen on creation', function (string $model, string $type) {
    $page = $model === 'contact'
        ? Livewire::test(CreateCustomField::class)->fillForm(['name' => 'Ref', 'type' => $type, 'options' => [['label' => 'A', 'value' => 'a']]])
        : Livewire::test(CreateCompanyCustomField::class)->fillForm(['company_type_id' => $this->companyType->id, 'name' => 'Ref', 'type' => $type, 'options' => [['label' => 'A', 'value' => 'a']]]);

    $page->call('create')->assertHasNoFormErrors();

    expect(($model === 'contact' ? CustomField::class : CompanyCustomField::class)::where('name', 'Ref')->sole()->type)->toBe($type);
})->with([
    'contact' => ['contact', 'number'],
    'company' => ['company', 'multiselect'],
]);

it('keeps the type enabled on the create page', function () {
    Livewire::test(CreateCustomField::class)
        ->assertFormFieldEnabled('type')
        ->assertSee('The type of data this field will store');
});

it('disables the type on the edit page', function (string $model) {
    $field = ($this->textField)($model);

    ($this->editPage)($model, $field)
        ->assertFormFieldDisabled('type')
        ->assertSee("The type can't change after the field is created.");
})->with(['contact', 'company']);

it('keeps the type and the values when the edit page is saved', function () {
    $field = ($this->textField)('contact');
    $contact = Contact::factory()->withCustomFields([$field->key => 'abc'])->create(['organization_id' => $this->org->id]);

    ($this->editPage)('contact', $field)
        ->fillForm(['name' => 'Reference'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($field->fresh()->type)->toBe('text')
        ->and($field->fresh()->name)->toBe('Reference')
        ->and($contact->fresh()->customFieldValue($field->key))->toBe('abc');
});

it('ignores a forged type in the request', function (string $model) {
    $field = ($this->textField)($model);

    ($this->editPage)($model, $field)
        ->set('data.type', 'number')
        ->call('save');

    expect($field->fresh()->type)->toBe('text');
})->with(['contact', 'company']);

it('throws when code changes the type', function (string $model) {
    $field = ($this->textField)($model);

    expect(fn () => $field->update(['type' => 'number']))
        ->toThrow(CustomFieldTypeLockedException::class, "can't change after it is created")
        ->and($field->fresh()->type)->toBe('text');
})->with(['contact', 'company']);

it('lets the options of a select field grow', function () {
    $field = CustomField::factory()->for($this->org)->select()->create();
    $options = [...$field->options, ['label' => 'Option C', 'value' => 'optc']];

    Repeater::fake();

    ($this->editPage)('contact', $field)
        ->fillForm(['options' => $options])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($field->fresh()->options)->toHaveCount(count($options));
});
