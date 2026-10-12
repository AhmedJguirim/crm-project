<?php

use App\Filament\Resources\CompanyCustomFields\Pages\CreateCompanyCustomField;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->valueOf = fn (CustomField $field, string $label): ?string => collect($field->fresh()->options)->firstWhere('label', $label)['value'] ?? null;
    $this->saveOptions = function (CustomField $field, array $options) {
        Repeater::fake();

        return Livewire::test(EditCustomField::class, ['record' => $field->getRouteKey()])->fillForm(['options' => $options])->call('save');
    };

    $this->createStack = function () {
        Livewire::test(CreateCustomField::class)
            ->fillForm(['name' => 'Stack', 'type' => 'multiselect', 'options' => [['label' => 'PHP'], ['label' => 'AWS']]])
            ->call('create')
            ->assertHasNoFormErrors();

        return CustomField::where('name', 'Stack')->sole();
    };
});

describe('creating a field', function () {
    it('generates the option values and shows no Value input', function () {
        $page = Livewire::test(CreateCustomField::class)
            ->fillForm(['name' => 'Stack', 'type' => 'multiselect', 'options' => [['label' => 'PHP'], ['label' => 'AWS']]])
            ->call('create')
            ->assertHasNoFormErrors();

        expect($page->instance()->getSchema('form')->getFlatFields()['options.0.value'])->toBeInstanceOf(Hidden::class);

        $values = collect(CustomField::where('name', 'Stack')->sole()->options)->pluck('value');

        expect($values)->toHaveCount(2)
            ->and($values->every(fn (string $value): bool => preg_match('/^opt_[a-z0-9]{10}$/', $value) === 1))->toBeTrue()
            ->and($values->unique())->toHaveCount(2);
    });

    it('keeps explicit values given in code', function () {
        $field = CustomField::factory()->for($this->org)->create(['type' => 'select', 'options' => [['label' => 'PHP', 'value' => 'php']]]);

        expect($field->fresh()->options)->toBe([['label' => 'PHP', 'value' => 'php']]);
    });

    it('generates the values of options declared without one in code', function () {
        $field = CustomField::factory()->for($this->org)->create(['type' => 'select', 'options' => [['label' => 'PHP'], ['label' => 'AWS', 'value' => 'aws']]]);

        expect($field->fresh()->options[0]['value'])->toMatch('/^opt_[a-z0-9]{10}$/')
            ->and($field->fresh()->options[1]['value'])->toBe('aws');
    });

    it('does the same for a company field', function () {
        $type = CompanyType::factory()->for($this->org)->create();

        $page = Livewire::test(CreateCompanyCustomField::class)
            ->fillForm(['company_type_id' => $type->id, 'name' => 'Sector', 'type' => 'select', 'options' => [['label' => 'Software']]])
            ->call('create')
            ->assertHasNoFormErrors();

        expect($page->instance()->getSchema('form')->getFlatFields()['options.0.value'])->toBeInstanceOf(Hidden::class);

        expect(CompanyCustomField::where('name', 'Sector')->sole()->options[0]['value'])->toMatch('/^opt_[a-z0-9]{10}$/');
    });
});

describe('editing the options of a field', function () {
    it('keeps the value and the contacts of a renamed option', function () {
        $field = ($this->createStack)();
        $aws = ($this->valueOf)($field, 'AWS');
        $contact = Contact::factory()->for($this->org)->withCustomFields([$field->key => [$aws]])->create();

        ($this->saveOptions)($field, [
            ['label' => 'PHP', 'value' => ($this->valueOf)($field, 'PHP')],
            ['label' => 'Amazon Web Services', 'value' => $aws],
        ])->assertHasNoFormErrors();

        expect(($this->valueOf)($field, 'Amazon Web Services'))->toBe($aws)
            ->and($contact->fresh()->customFieldValue($field->key))->toBe([$aws])
            ->and($field->fresh()->formatValue([$aws]))->toBe('Amazon Web Services');

        $page = Livewire::test(EditContact::class, ['record' => $contact->id]);

        expect($page->instance()->getSchema('form')->getFlatFields()["custom_field_values.{$field->key}"]->getOptions()[$aws])->toBe('Amazon Web Services');
    });

    it('gives a new option a value and keeps the others', function () {
        $field = ($this->createStack)();
        [$php, $aws] = [($this->valueOf)($field, 'PHP'), ($this->valueOf)($field, 'AWS')];

        ($this->saveOptions)($field, [
            ['label' => 'PHP', 'value' => $php],
            ['label' => 'AWS', 'value' => $aws],
            ['label' => 'React', 'value' => null],
        ])->assertHasNoFormErrors();

        expect(($this->valueOf)($field, 'React'))->toMatch('/^opt_[a-z0-9]{10}$/')
            ->and(($this->valueOf)($field, 'PHP'))->toBe($php)
            ->and(($this->valueOf)($field, 'AWS'))->toBe($aws);
    });

    it('keeps the values when the options are reordered', function () {
        $field = ($this->createStack)();
        [$php, $aws] = [($this->valueOf)($field, 'PHP'), ($this->valueOf)($field, 'AWS')];

        ($this->saveOptions)($field, [['label' => 'AWS', 'value' => $aws], ['label' => 'PHP', 'value' => $php]])->assertHasNoFormErrors();

        expect(collect($field->fresh()->options)->pluck('value')->all())->toBe([$aws, $php]);
    });

    it('replaces a value forged for a new option', function () {
        $field = ($this->createStack)();
        [$php, $aws] = [($this->valueOf)($field, 'PHP'), ($this->valueOf)($field, 'AWS')];

        ($this->saveOptions)($field, [
            ['label' => 'PHP', 'value' => $php],
            ['label' => 'AWS', 'value' => $aws],
            ['label' => 'Sneaky', 'value' => 'something-typed'],
            ['label' => 'Copycat', 'value' => $aws],
        ])->assertHasNoFormErrors();

        $values = collect($field->fresh()->options)->pluck('value');

        expect($values)->toHaveCount(4)
            ->and($values->unique())->toHaveCount(4)
            ->and(($this->valueOf)($field, 'Sneaky'))->toMatch('/^opt_[a-z0-9]{10}$/')
            ->and(($this->valueOf)($field, 'Copycat'))->toMatch('/^opt_[a-z0-9]{10}$/')
            ->and(($this->valueOf)($field, 'AWS'))->toBe($aws);
    });

    it('never changes the value of an existing option in code either', function () {
        $field = CustomField::factory()->for($this->org)->create(['type' => 'select', 'options' => [['label' => 'PHP', 'value' => 'php']]]);

        $field->update(['options' => [['label' => 'PHP / Laravel', 'value' => 'php'], ['label' => 'AWS', 'value' => 'aws']]]);

        expect(($this->valueOf)($field, 'PHP / Laravel'))->toBe('php')
            ->and(($this->valueOf)($field, 'AWS'))->toMatch('/^opt_[a-z0-9]{10}$/');
    });

    it('refuses names that only differ by case or spaces', function () {
        $field = ($this->createStack)();

        ($this->saveOptions)($field, [['label' => 'AWS', 'value' => ($this->valueOf)($field, 'AWS')], ['label' => ' aws ', 'value' => null]])
            ->assertHasFormErrors(['options' => 'Each option needs a different name.']);
    });

    it('refuses a name with a semicolon', function () {
        $field = ($this->createStack)();

        $page = ($this->saveOptions)($field, [['label' => 'A;B', 'value' => null]])->assertHasFormErrors(['options.0.label']);

        expect($page->errors()->get('data.options.0.label'))->toBe(['Option names can\'t contain ";": it separates several values in imports.']);
    });

    it('still refuses to remove an option contacts use', function () {
        $field = ($this->createStack)();
        $aws = ($this->valueOf)($field, 'AWS');
        Contact::factory()->for($this->org)->withCustomFields([$field->key => [$aws]])->create(['name' => 'Ann']);

        ($this->saveOptions)($field, [['label' => 'PHP', 'value' => ($this->valueOf)($field, 'PHP')]])
            ->assertHasFormErrors(['options' => 'The option "AWS" is still set on 1 contact (Ann). Remove it from those contacts first, or keep the option.']);
    });
});
