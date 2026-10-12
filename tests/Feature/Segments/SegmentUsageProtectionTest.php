<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Exceptions\CustomFieldTypeLockedException;
use App\Exceptions\UsedInSegmentsException;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\CompanyCustomFields\Pages\ListCompanyCustomFields;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\Tags\Pages\EditTag;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Services\Segments\SegmentUsage;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function segmentWithCondition(SegmentConditionData $condition, array $attributes = [], string $name = 'Newsletter', bool $asDraft = false): Segment
{
    $rules = [new SegmentRuleData('rule-1', 'Rule', [$condition])];

    return Segment::factory()
        ->for(test()->org)
        ->create([
            'name' => $name,
            'rules' => $asDraft ? [] : collect($rules)->map->toArray()->all(),
            'draft_rules' => $asDraft ? collect($rules)->map->toArray()->all() : null,
            ...$attributes,
        ]);
}

/**
 * @return array{0: Model, 1: SegmentConditionData}
 */
function referencedRecord(string $kind): array
{
    $org = test()->org;

    return match ($kind) {
        'custom field' => [
            $field = CustomField::factory()->for($org)->text()->create(['name' => 'Industry']),
            SegmentConditionData::make(SegmentConditionType::CustomField, $field->key, SegmentOperator::IsNotBlank),
        ],
        'tag' => [
            $tag = Tag::factory()->for($org)->create(['name' => 'VIP']),
            SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
        ],
        'company' => [
            $company = Company::factory()->for($org)->create(['name' => 'Acme']),
            SegmentConditionData::make(SegmentConditionType::Company, null, SegmentOperator::BelongsToAnyOf, ['values' => [$company->id]]),
        ],
        'company excluded' => [
            $company = Company::factory()->for($org)->create(['name' => 'Acme']),
            SegmentConditionData::make(SegmentConditionType::Company, null, SegmentOperator::BelongsToNoneOf, ['values' => [$company->id]]),
        ],
        'company type' => [
            $companyType = CompanyType::factory()->for($org)->create(['name' => 'Agency']),
            SegmentConditionData::make(SegmentConditionType::Company, null, SegmentOperator::CompanyTypeIsAnyOf, ['values' => [$companyType->id]]),
        ],
        'tag stored as a string id' => [
            $tag = Tag::factory()->for($org)->create(['name' => 'VIP']),
            SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAllOf, ['values' => [(string) $tag->id]]),
        ],
    };
}

dataset('referenced records', ['custom field', 'tag', 'company', 'company excluded', 'company type', 'tag stored as a string id']);

describe('model guard', function () {
    it('refuses to delete a record used in published rules', function (string $kind) {
        [$record, $condition] = referencedRecord($kind);
        segmentWithCondition($condition, ['is_published' => true]);

        expect(fn () => $record->delete())->toThrow(UsedInSegmentsException::class, 'used in the conditions of the segment "Newsletter"');
        $this->assertNotSoftDeleted($record);
    })->with('referenced records');

    it('refuses to delete a record only used in an unsaved draft', function (string $kind) {
        [$record, $condition] = referencedRecord($kind);
        segmentWithCondition($condition, ['is_published' => true], asDraft: true);

        expect(fn () => $record->delete())->toThrow(UsedInSegmentsException::class);
        $this->assertNotSoftDeleted($record);
    })->with('referenced records');

    it('refuses to delete a record used by a segment that was never published', function (string $kind) {
        [$record, $condition] = referencedRecord($kind);
        segmentWithCondition($condition);

        expect(fn () => $record->delete())->toThrow(UsedInSegmentsException::class);
    })->with('referenced records');

    it('allows deleting a record once no segment uses it anymore', function (string $kind) {
        [$record, $condition] = referencedRecord($kind);
        $segment = segmentWithCondition($condition);

        $segment->delete();
        $record->delete();

        $this->assertSoftDeleted($record);
    })->with('referenced records');

    it('allows deleting a record once its condition is removed', function () {
        [$tag, $condition] = referencedRecord('tag');
        $segment = segmentWithCondition($condition);

        $segment->storeWorkingRules([new SegmentRuleData('rule-1', 'Rule', [])]);
        $tag->delete();

        $this->assertSoftDeleted($tag);
    });

    it('lists every segment using the record', function () {
        [$tag, $condition] = referencedRecord('tag');
        segmentWithCondition($condition, name: 'Newsletter');
        segmentWithCondition($condition, name: 'Black Friday');

        expect(fn () => $tag->delete())->toThrow(UsedInSegmentsException::class, 'the segments "Black Friday" and "Newsletter"');
    });

    it('does not confuse records of different kinds sharing an id', function () {
        $company = Company::factory()->for($this->org)->create();
        $companyType = CompanyType::factory()->for($this->org)->create(['id' => $company->id]);
        segmentWithCondition(SegmentConditionData::make(SegmentConditionType::Company, null, SegmentOperator::CompanyTypeIsAnyOf, ['values' => [$companyType->id]]));
        $tag = Tag::factory()->for($this->org)->create(['id' => $company->id]);

        $company->delete();
        $tag->delete();

        $this->assertSoftDeleted($company);
        $this->assertSoftDeleted($tag);
        expect(fn () => $companyType->delete())->toThrow(UsedInSegmentsException::class);
    });

    it('only looks at the segments of the record organization', function () {
        [$tag, $condition] = referencedRecord('tag');
        Segment::factory()->for(Organization::factory()->create())->withRules([new SegmentRuleData('rule-1', 'Rule', [$condition])])->create();

        $tag->delete();

        $this->assertSoftDeleted($tag);
    });

    it('does not protect company custom fields, which segments cannot reference', function () {
        $field = CompanyCustomField::factory()->for($this->org)->create([]);
        segmentWithCondition(SegmentConditionData::make(SegmentConditionType::CustomField, $field->key, SegmentOperator::IsNotBlank));

        $field->delete();

        $this->assertSoftDeleted($field);
    });
});

describe('delete actions', function () {
    it('disables the delete action of used records, naming the segments', function (string $page, string $kind, bool $onTable) {
        [$record, $condition] = referencedRecord($kind);
        segmentWithCondition($condition);

        $action = $onTable ? TestAction::make('delete')->table($record) : TestAction::make('delete');
        $component = $onTable
            ? Livewire::test($page)
            : Livewire::test($page, ['record' => $record->getRouteKey()]);

        $component
            ->assertActionDisabled($action)
            ->assertActionExists($action, fn (Action $action): bool => str_contains((string) $action->getTooltip(), 'Used in the conditions of the segment "Newsletter"'));

        $this->assertNotSoftDeleted($record);
    })->with([
        'tags list' => [ListTags::class, 'tag', true],
        'tag edit page' => [EditTag::class, 'tag', false],
        'companies list' => [ListCompanies::class, 'company', true],
        'company edit page' => [EditCompany::class, 'company', false],
        'custom fields list' => [ListCustomFields::class, 'custom field', true],
        'custom field edit page' => [EditCustomField::class, 'custom field', false],
    ]);

    it('keeps the delete action enabled for unused records', function () {
        $tag = Tag::factory()->for($this->org)->create();

        Livewire::test(ListTags::class)
            ->assertActionEnabled(TestAction::make('delete')->table($tag))
            ->callAction(TestAction::make('delete')->table($tag));

        $this->assertSoftDeleted($tag);
    });

    it('bulk deletes the unused records and reports the used ones', function (string $page, string $kind) {
        [$used, $condition] = referencedRecord($kind);
        segmentWithCondition($condition);
        $unused = $used->replicate(['key'])->fill(['name' => 'Unused']);
        $unused->save();

        Livewire::test($page)
            ->selectTableRecords([$used, $unused])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertNotSoftDeleted($used);
        $this->assertSoftDeleted($unused);

        $notifications = new Notifications;
        $notifications->mount();

        expect($notifications->notifications->last()->getBody())
            ->toContain('1 record was kept because it is used in the conditions of: "Newsletter".');
    })->with([
        'tags' => [ListTags::class, 'tag'],
        'companies' => [ListCompanies::class, 'company'],
        'custom fields' => [ListCustomFields::class, 'custom field'],
    ]);

    it('still bulk deletes company custom fields', function () {
        $companyType = CompanyType::factory()->for($this->org)->create();
        $fields = CompanyCustomField::factory()->for($this->org)->count(2)->create([]);

        Livewire::test(ListCompanyCustomFields::class)
            ->selectTableRecords($fields)
            ->callAction(TestAction::make('delete')->table()->bulk());

        $fields->each(fn (CompanyCustomField $field) => $this->assertSoftDeleted($field));
    });
});

describe('custom field edits', function () {
    beforeEach(function () {
        $this->plan = CustomField::factory()->for($this->org)->select()->create(['name' => 'Plan']);
        $this->segment = segmentWithCondition(SegmentConditionData::make(
            SegmentConditionType::CustomField, $this->plan->key, SegmentOperator::IsAnyOf, ['values' => ['opt1', 'opt2']],
        ));
    });

    it('locks the type of a field used by a segment', function () {
        Livewire::test(EditCustomField::class, ['record' => $this->plan->getRouteKey()])
            ->assertFormFieldDisabled('type')
            ->assertSee("The type can't change after the field is created.");

        expect(fn () => $this->plan->update(['type' => 'text']))->toThrow(CustomFieldTypeLockedException::class, "can't change after it is created");
        expect($this->plan->fresh()->type)->toBe('select');
    });

    it('locks the type of an unused field too', function () {
        $unused = CustomField::factory()->for($this->org)->text()->create();

        Livewire::test(EditCustomField::class, ['record' => $unused->getRouteKey()])
            ->assertFormFieldDisabled('type');
    });

    it('refuses to remove options used in conditions', function () {
        Repeater::fake();

        Livewire::test(EditCustomField::class, ['record' => $this->plan->getRouteKey()])
            ->fillForm(['options' => [
                ['label' => 'Option 1', 'value' => 'opt1'],
                ['label' => 'Option 3', 'value' => 'opt3'],
            ]])
            ->call('save')
            ->assertHasFormErrors(['options']);

        expect(collect($this->plan->fresh()->options)->pluck('value')->all())->toBe(['opt1', 'opt2', 'opt3']);
        expect(fn () => $this->plan->update(['options' => [['label' => 'Option 1', 'value' => 'opt1']]]))
            ->toThrow(UsedInSegmentsException::class, '"opt2" (used by Newsletter)');
    });

    it('allows removing unused options and renaming labels', function () {
        Repeater::fake();

        Livewire::test(EditCustomField::class, ['record' => $this->plan->getRouteKey()])
            ->fillForm(['options' => [
                ['label' => 'Starter', 'value' => 'opt1'],
                ['label' => 'Pro', 'value' => 'opt2'],
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($this->plan->fresh()->optionLabels())->toBe(['opt1' => 'Starter', 'opt2' => 'Pro']);
    });

    it('protects options used by an equality condition in a draft', function () {
        $this->segment->delete();
        segmentWithCondition(SegmentConditionData::make(
            SegmentConditionType::CustomField, $this->plan->key, SegmentOperator::Is, ['value' => 'opt3'],
        ), asDraft: true);

        expect(SegmentUsage::usedOptionValues($this->plan))->toBe(['opt3' => ['Newsletter']])
            ->and(fn () => $this->plan->update(['options' => [['label' => 'Option 1', 'value' => 'opt1']]]))
            ->toThrow(UsedInSegmentsException::class);
    });

    it('frees the field once the segment is deleted', function () {
        $this->segment->delete();

        $this->plan->update(['options' => [['label' => 'Option 9', 'value' => 'opt9']]]);

        expect($this->plan->fresh()->options)->toHaveCount(1)
            ->and($this->plan->fresh()->options[0]['label'])->toBe('Option 9');
    });
});
