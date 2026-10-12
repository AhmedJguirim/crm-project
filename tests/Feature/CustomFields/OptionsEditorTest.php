<?php

use App\Filament\Resources\CompanyCustomFields\Pages\CreateCompanyCustomField;
use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->optionsField = CustomField::factory()->for($this->org)->create(['name' => 'Tools', 'type' => 'multiselect', 'unique' => false, 'options' => [['label' => 'Jira', 'value' => 'jira']]]);
    $this->editPage = fn () => Livewire::test(EditCustomField::class, ['record' => $this->optionsField->getRouteKey()]);
    $this->labels = fn ($page): array => collect($page->get('data.options'))->pluck('label')->values()->all();
    $this->paste = fn ($page, string $lines) => $page->callAction(TestAction::make('pasteSeveralOptions')->schemaComponent('options'), ['lines' => $lines]);
});

describe('the layout', function () {
    it('is a table with one input per row on the contact form', function () {
        $fields = Livewire::test(CreateCustomField::class)
            ->fillForm(['type' => 'multiselect', 'options' => [['label' => 'Jira']]])
            ->instance()->getSchema('form')->getFlatFields();

        expect($fields['options'])->toBeInstanceOf(Repeater::class)
            ->and($fields['options']->getTableColumns())->toHaveCount(1)
            ->and($fields['options']->isReorderableWithDragAndDrop())->toBeTrue()
            ->and($fields['options']->isReorderableWithButtons())->toBeFalse()
            ->and($fields['options']->getAddActionLabel())->toBe('Add option')
            ->and($fields['options.0.label'])->toBeInstanceOf(TextInput::class)
            ->and($fields['options.0.label']->getPlaceholder())->toBe('Option name')
            ->and($fields['options.0.label']->getLabel())->toBe('Label')
            ->and($fields['options.0.label']->isLabelHidden())->toBeTrue()
            ->and($fields['options.0.value'])->toBeInstanceOf(Hidden::class);
    });

    it('is the same table on the company form', function () {
        CompanyType::factory()->for($this->org)->create();

        $fields = Livewire::test(CreateCompanyCustomField::class)
            ->fillForm(['type' => 'select', 'options' => [['label' => 'Software']]])
            ->instance()->getSchema('form')->getFlatFields();

        expect($fields['options']->getTableColumns())->toHaveCount(1)
            ->and($fields['options.0.label']->getPlaceholder())->toBe('Option name');
    });
});

describe('creating, renaming and reordering', function () {
    it('still keeps the generated values', function () {
        Livewire::test(CreateCustomField::class)
            ->fillForm(['name' => 'Chat', 'type' => 'multiselect', 'options' => [['label' => 'Jira'], ['label' => 'Slack']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $field = CustomField::where('name', 'Chat')->sole();
        [$jira, $slack] = collect($field->options)->pluck('value')->all();

        Repeater::fake();
        Livewire::test(EditCustomField::class, ['record' => $field->getRouteKey()])
            ->fillForm(['options' => [['label' => 'Slack chat', 'value' => $slack], ['label' => 'Jira', 'value' => $jira]]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($field->fresh()->options)->toBe([['label' => 'Slack chat', 'value' => $slack], ['label' => 'Jira', 'value' => $jira]]);
    });
});

describe('pasting several options', function () {
    it('adds one option per new line, skipping blanks and names already there', function () {
        Repeater::fake();
        $page = ($this->editPage)();

        ($this->paste)($page, "Slack\n\n jira \nTeams\nSlack")->assertNotified('Added 2 options (2 already existed).');

        expect(($this->labels)($page))->toBe(['Jira', 'Slack', 'Teams'])
            ->and(collect($page->get('data.options'))->pluck('value')->values()->all())->toBe(['jira', null, null])
            ->and($this->optionsField->fresh()->options)->toBe([['label' => 'Jira', 'value' => 'jira']]);

        $page->call('save')->assertHasNoFormErrors();

        $values = collect($this->optionsField->fresh()->options)->pluck('value');

        expect($values[0])->toBe('jira')
            ->and($values->slice(1)->every(fn (string $value): bool => preg_match('/^opt_[a-z0-9]{10}$/', $value) === 1))->toBeTrue();
    });

    it('reports a single option', function () {
        Repeater::fake();

        ($this->paste)(($this->editPage)(), 'Slack')->assertNotified('Added 1 option.');
    });

    it('refuses a line with a semicolon, naming it, and adds nothing', function () {
        Repeater::fake();
        $page = ($this->editPage)();

        ($this->paste)($page, "A;B\nC")->assertHasFormErrors(['lines']);

        expect(($this->labels)($page))->toBe(['Jira'])
            ->and($page->errors()->get('mountedActions.0.data.lines'))->toBe(['"A;B": Option names can\'t contain ";": it separates several values in imports.']);
    });
});

describe('the errors', function () {
    it('still shows the duplicate names error', function () {
        Repeater::fake();

        ($this->editPage)()
            ->fillForm(['options' => [['label' => 'AWS', 'value' => null], ['label' => 'aws', 'value' => null]]])
            ->call('save')
            ->assertHasFormErrors(['options' => 'Each option needs a different name.']);
    });

    it('still shows the message of the options contacts use', function () {
        Contact::factory()->for($this->org)->withCustomFields([$this->optionsField->key => ['jira']])->create(['name' => 'Ann']);
        Repeater::fake();

        ($this->editPage)()
            ->fillForm(['options' => [['label' => 'Slack', 'value' => null]]])
            ->call('save')
            ->assertHasFormErrors(['options' => 'The option "Jira" is still set on 1 contact (Ann). Remove it from those contacts first, or keep the option.']);
    });
});
