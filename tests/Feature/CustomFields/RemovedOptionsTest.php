<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\CompanyCustomFields\Pages\EditCompanyCustomField;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use App\Support\CustomFields\OptionUsage;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->stack = CustomField::factory()->for($this->org)->create([
        'name' => 'Tech Stack', 'type' => 'multiselect', 'unique' => false,
        'options' => [['label' => 'PHP', 'value' => 'php'], ['label' => 'AWS', 'value' => 'aws'], ['label' => 'React', 'value' => 'react']],
    ]);
    $this->seniority = CustomField::factory()->for($this->org)->create([
        'name' => 'Seniority', 'type' => 'select', 'unique' => false,
        'options' => [['label' => 'Junior', 'value' => 'junior'], ['label' => 'Senior', 'value' => 'senior']],
    ]);

    $this->contactWith = fn (string $name, array $values): Contact => Contact::factory()->for($this->org)->withCustomFields($values)->create(['name' => $name]);
    $this->saveOptions = function (CustomField|CompanyCustomField $field, array $options) {
        Repeater::fake();

        return Livewire::test($field instanceof CompanyCustomField ? EditCompanyCustomField::class : EditCustomField::class, ['record' => $field->getRouteKey()])
            ->fillForm(['options' => $options])
            ->call('save');
    };
});

describe('removing an option that records still store', function () {
    it('is refused, naming the option and the contacts', function () {
        ($this->contactWith)('Marcus Chen', [$this->stack->key => ['php', 'aws']]);
        ($this->contactWith)('Priya Nair', [$this->stack->key => ['aws']]);

        ($this->saveOptions)($this->stack, [['label' => 'PHP', 'value' => 'php'], ['label' => 'React', 'value' => 'react']])
            ->assertHasFormErrors(['options' => 'The option "AWS" is still set on 2 contacts (Marcus Chen, Priya Nair). Remove it from those contacts first, or keep the option.']);

        expect($this->stack->fresh()->options)->toHaveCount(3);
    });

    it('is refused when only the stored value of a used option changes', function () {
        ($this->contactWith)('Marcus Chen', [$this->seniority->key => 'senior']);

        ($this->saveOptions)($this->seniority, [['label' => 'Junior', 'value' => 'junior'], ['label' => 'Senior', 'value' => 'sr']])
            ->assertHasFormErrors(['options' => 'The option "Senior" is still set on 1 contact (Marcus Chen). Remove it from those contacts first, or keep the option.']);
    });

    it('shows three names at most', function () {
        foreach (['Eve', 'Dan', 'Cat', 'Bob', 'Ann'] as $name) {
            ($this->contactWith)($name, [$this->stack->key => ['aws']]);
        }

        ($this->saveOptions)($this->stack, [['label' => 'PHP', 'value' => 'php'], ['label' => 'React', 'value' => 'react']])
            ->assertHasFormErrors(['options' => 'The option "AWS" is still set on 5 contacts (Ann, Bob, Cat and 2 more). Remove it from those contacts first, or keep the option.']);
    });

    it('counts trashed contacts', function () {
        ($this->contactWith)('Gone Gary', [$this->stack->key => ['aws']])->delete();

        ($this->saveOptions)($this->stack, [['label' => 'PHP', 'value' => 'php'], ['label' => 'React', 'value' => 'react']])
            ->assertHasFormErrors(['options']);
    });

    it('ignores the contacts of another organization', function () {
        $other = Organization::factory()->create();
        Contact::factory()->for($other)->withCustomFields([$this->stack->key => ['aws']])->create();

        ($this->saveOptions)($this->stack, [['label' => 'PHP', 'value' => 'php'], ['label' => 'React', 'value' => 'react']])
            ->assertHasNoFormErrors();

        expect($this->stack->fresh()->options)->toHaveCount(2);
    });

    it('allows removing an unused option and renaming a label', function () {
        ($this->contactWith)('Marcus Chen', [$this->stack->key => ['php']]);

        ($this->saveOptions)($this->stack, [['label' => 'PHP / Laravel', 'value' => 'php'], ['label' => 'AWS', 'value' => 'aws']])
            ->assertHasNoFormErrors();

        expect($this->stack->fresh()->options)->toHaveCount(2)
            ->and($this->stack->fresh()->optionLabels()['php'])->toBe('PHP / Laravel');
    });

    it('is checked against companies for a company field', function () {
        $type = CompanyType::factory()->for($this->org)->create();
        $industry = CompanyCustomField::factory()->for($this->org)->create([
            'company_type_id' => $type->id, 'name' => 'Industry', 'type' => 'select', 'unique' => false,
            'options' => [['label' => 'Tech', 'value' => 'tech'], ['label' => 'Retail', 'value' => 'retail']],
        ]);
        Company::factory()->for($this->org)->create(['name' => 'Acme', 'company_type_id' => $type->id, 'custom_field_values' => [$industry->key => 'tech']]);

        ($this->saveOptions)($industry, [['label' => 'Retail', 'value' => 'retail']])
            ->assertHasFormErrors(['options' => 'The option "Tech" is still set on 1 company (Acme). Remove it from those companies first, or keep the option.']);
    });

    it('still reports the segments that use the option', function () {
        $segment = Segment::factory()->for($this->org)->create([
            'name' => 'PHP people',
            'rules' => [(new SegmentRuleData('rule-1', 'Rule', [
                SegmentConditionData::make(SegmentConditionType::CustomField, $this->stack->key, SegmentOperator::IsAnyOf, ['values' => ['php']]),
            ]))->toArray()],
        ]);

        ($this->saveOptions)($this->stack, [['label' => 'AWS', 'value' => 'aws'], ['label' => 'React', 'value' => 'react']])
            ->assertHasFormErrors(['options']);

        expect($segment->exists)->toBeTrue()
            ->and($this->stack->fresh()->options)->toHaveCount(3);
    });

});

describe('the usage of an option', function () {
    it('finds multi-select and select values, and nothing for an unused one', function () {
        ($this->contactWith)('Marcus Chen', [$this->stack->key => ['php', 'aws'], $this->seniority->key => 'senior']);

        expect(OptionUsage::recordsUsingOption($this->stack, 'aws')->count())->toBe(1)
            ->and(OptionUsage::recordsUsingOption($this->stack, 'react')->count())->toBe(0)
            ->and(OptionUsage::recordsUsingOption($this->seniority, 'senior')->count())->toBe(1)
            ->and(OptionUsage::recordsUsingOption($this->seniority, 'junior')->count())->toBe(0)
            ->and(OptionUsage::describeRemovedOptions($this->stack, ['react']))->toBeNull();
    });
});

describe('the scope of the usage', function () {
    it('is the organization of the field, whatever the current tenant', function () {
        $other = Organization::factory()->create();
        ($this->contactWith)('Marcus Chen', [$this->stack->key => ['aws']]);
        ($this->contactWith)('Priya Nair', [$this->stack->key => ['aws']]);
        Contact::factory()->for($other)->withCustomFields([$this->stack->key => ['aws']])->create();

        Filament::setTenant($other);

        $count = OptionUsage::recordsUsingOption($this->stack, 'aws')->count();

        expect($count)->toBe(2);
    });

    it('counts trashed companies for a company field', function () {
        $type = CompanyType::factory()->for($this->org)->create();
        $industry = CompanyCustomField::factory()->for($this->org)->create([
            'company_type_id' => $type->id, 'type' => 'select', 'unique' => false,
            'options' => [['label' => 'Tech', 'value' => 'tech']],
        ]);
        Company::factory()->for($this->org)->create(['company_type_id' => $type->id, 'custom_field_values' => [$industry->key => 'tech']])->delete();

        expect(OptionUsage::recordsUsingOption($industry, 'tech')->count())->toBe(1);
    });
});

describe('records that hold a removed value', function () {
    beforeEach(function () {
        $this->stack->update(['options' => [['label' => 'PHP', 'value' => 'php'], ['label' => 'React', 'value' => 'react']]]);
        $this->seniority->update(['options' => [['label' => 'Junior', 'value' => 'junior']]]);
        $this->stackPath = "custom_field_values.{$this->stack->key}";
        $this->seniorityPath = "custom_field_values.{$this->seniority->key}";
    });

    it('shows the removed multi-select value, saves unchanged and lets the user clear it', function () {
        $contact = ($this->contactWith)('Marcus Chen', [$this->stack->key => ['php', 'aws']]);

        $page = Livewire::test(EditContact::class, ['record' => $contact->id])
            ->assertFormSet([$this->stackPath => ['php', 'aws']]);

        expect($page->instance()->getSchema('form')->getFlatFields()[$this->stackPath]->getOptions())
            ->toBe(['php' => 'PHP', 'react' => 'React', 'aws' => 'aws (removed option)']);

        $page->call('save')->assertHasNoFormErrors();
        expect($contact->fresh()->customFieldValue($this->stack->key))->toBe(['php', 'aws']);

        $page->fillForm([$this->stackPath => ['php', 'react']])->call('save')->assertHasNoFormErrors();
        expect($contact->fresh()->customFieldValue($this->stack->key))->toBe(['php', 'react']);
    });

    it('shows the removed select value, saves unchanged and lets the user clear it', function () {
        $contact = ($this->contactWith)('Marcus Chen', [$this->seniority->key => 'senior']);

        $page = Livewire::test(EditContact::class, ['record' => $contact->id]);

        expect($page->instance()->getSchema('form')->getFlatFields()[$this->seniorityPath]->getOptions())
            ->toBe(['junior' => 'Junior', 'senior' => 'senior (removed option)']);

        $page->call('save')->assertHasNoFormErrors();
        expect($contact->fresh()->customFieldValue($this->seniority->key))->toBe('senior');

        $page->fillForm([$this->seniorityPath => null])->call('save')->assertHasNoFormErrors();
        expect($contact->fresh()->customFieldValue($this->seniority->key))->toBeNull();
    });

    it('does the same for a company', function () {
        $type = CompanyType::factory()->for($this->org)->create();
        $industry = CompanyCustomField::factory()->for($this->org)->create([
            'company_type_id' => $type->id, 'type' => 'select', 'unique' => false,
            'options' => [['label' => 'Retail', 'value' => 'retail']],
        ]);
        $company = Company::factory()->for($this->org)->create(['company_type_id' => $type->id, 'custom_field_values' => [$industry->key => 'tech']]);

        Livewire::test(EditCompany::class, ['record' => $company->id])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($company->fresh()->custom_field_values[$industry->key])->toBe('tech');
    });

    it('offers only the real options on the create form', function () {
        $page = Livewire::test(CreateContact::class)->set('data.custom_field_picker', $this->stack->key);

        expect($page->instance()->getSchema('form')->getFlatFields()[$this->stackPath]->getOptions())
            ->toBe(['php' => 'PHP', 'react' => 'React']);
    });
});
