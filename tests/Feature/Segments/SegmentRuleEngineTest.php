<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Filament\Resources\Segments\SegmentResource;
use App\Jobs\SyncSegmentMembership;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Services\Segments\SegmentFieldCatalog;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function ruleEngineSegment(array $rules = [], array $attributes = []): Segment
{
    return Segment::factory()
        ->for(test()->org)
        ->withRules($rules)
        ->create($attributes);
}

function leadRule(string $id = 'rule-1', string $name = 'Leads'): SegmentRuleData
{
    return new SegmentRuleData($id, $name, [
        new SegmentConditionData('condition-1', SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
    ]);
}

function ruleEngine(Segment $segment): mixed
{
    return Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()]);
}

describe('page', function () {
    it('renders over HTTP', function () {
        $segment = ruleEngineSegment([leadRule()]);

        $this->get(SegmentResource::getUrl('rules', ['record' => $segment]))
            ->assertSuccessful()
            ->assertSee("Edit '{$segment->name}' Segment")
            ->assertSee('Rules list');
    });

    it('shows the empty state without rules', function () {
        ruleEngine(ruleEngineSegment())
            ->assertSuccessful()
            ->assertSee('No Rules Found')
            ->assertSee('Create your first rule to start defining conditions for this segment.')
            ->assertActionVisible('createRule');
    });

    it('selects the first rule and shows its condition sentences and live counts', function () {
        Contact::factory()->for($this->org)->count(2)->create(['status' => ContactStatus::Lead]);
        Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);

        ruleEngine(ruleEngineSegment([leadRule()]))
            ->assertSet('selectedRuleId', 'rule-1')
            ->assertSee("Rule 'Leads' conditions set")
            ->assertSeeHtml('If the contact <span class="font-semibold text-primary-600 dark:text-primary-400">Status</span> is <span class="font-semibold text-primary-600 dark:text-primary-400">Lead</span>')
            ->assertSee('2 matches');
    });

    it('switches the selected rule', function () {
        ruleEngine(ruleEngineSegment([leadRule(), leadRule('rule-2', 'Second')]))
            ->call('selectRule', 'rule-2')
            ->assertSet('selectedRuleId', 'rule-2')
            ->assertSee("Rule 'Second' conditions set");
    });

    it('flags incomplete conditions referencing deleted fields', function () {
        $rule = new SegmentRuleData('rule-1', 'Broken', [
            new SegmentConditionData('condition-1', SegmentConditionType::CustomField, 'cf_missing', SegmentOperator::IsNotBlank),
        ]);

        ruleEngine(ruleEngineSegment([$rule]))
            ->assertSee('Incomplete')
            ->assertSee('Unknown field (deleted)')
            ->assertActionDisabled('publish');
    });

    it('does not crash on a stored condition with too many days', function () {
        $rule = new SegmentRuleData('rule-1', 'Huge', [
            new SegmentConditionData('condition-1', SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::WithinLastDays, ['days' => 999999999]),
        ]);

        ruleEngine(ruleEngineSegment([$rule]))
            ->assertSuccessful()
            ->assertSee('Incomplete')
            ->assertActionDisabled('publish');
    });

    it('is not reachable for segments of another organization', function () {
        $this->get(SegmentResource::getUrl('rules', ['record' => Segment::factory()->create()]))
            ->assertNotFound();
    });
});

describe('rules', function () {
    it('creates a rule and selects it', function () {
        $segment = ruleEngineSegment();

        $page = ruleEngine($segment)
            ->callAction('createRule', ['name' => 'Big spenders'])
            ->assertHasNoActionErrors();

        $rule = $segment->fresh()->workingRules()->sole();

        expect($rule->name)->toBe('Big spenders')
            ->and($rule->conditions)->toBe([]);

        $page->assertSet('selectedRuleId', $rule->id);
    });

    it('rejects duplicate rule names case-insensitively', function () {
        ruleEngine(ruleEngineSegment([leadRule()]))
            ->callAction('createRule', ['name' => ' leads '])
            ->assertHasActionErrors(['name']);
    });

    it('requires a rule name', function () {
        ruleEngine(ruleEngineSegment())
            ->callAction('createRule', ['name' => ''])
            ->assertHasActionErrors(['name' => 'required']);
    });

    it('renames a rule, allowing its own name', function () {
        $segment = ruleEngineSegment([leadRule(), leadRule('rule-2', 'Other')]);

        ruleEngine($segment)
            ->callAction(TestAction::make('renameRule')->arguments(['rule' => 'rule-1']), ['name' => 'Other'])
            ->assertHasActionErrors(['name']);

        ruleEngine($segment)
            ->callAction(TestAction::make('renameRule')->arguments(['rule' => 'rule-1']), ['name' => 'LEADS'])
            ->assertHasNoActionErrors();

        expect($segment->fresh()->workingRules()->pluck('name')->all())->toBe(['LEADS', 'Other']);
    });

    it('deletes a rule and selects the next one', function () {
        $segment = ruleEngineSegment([leadRule(), leadRule('rule-2', 'Other')]);

        ruleEngine($segment)
            ->callAction(TestAction::make('deleteRule')->arguments(['rule' => 'rule-1']))
            ->assertSet('selectedRuleId', 'rule-2');

        expect($segment->fresh()->workingRules()->pluck('id')->all())->toBe(['rule-2']);
    });
});

describe('counts', function () {
    beforeEach(function () {
        Contact::factory()->for($this->org)->count(5)->create(['status' => ContactStatus::Lead, 'name' => 'Alice Lead']);
        Contact::factory()->for($this->org)->count(3)->create(['status' => ContactStatus::Partner, 'name' => 'Bob Partner']);
        Contact::factory()->for($this->org)->count(2)->create(['status' => ContactStatus::Lead, 'name' => 'Carol Lead']);

        $this->countedSegment = ruleEngineSegment([
            leadRule(),
            statusRuleFor('rule-2', 'Partners', ContactStatus::Partner),
            statusRuleFor('rule-3', 'Customers', ContactStatus::ActiveClient),
        ]);
    });

    function statusRuleFor(string $id, string $name, ContactStatus $status): SegmentRuleData
    {
        return new SegmentRuleData($id, $name, [
            new SegmentConditionData("{$id}-condition", SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => $status->value]),
        ]);
    }

    function ruleEngineCountQueries(): array
    {
        return collect(DB::getQueryLog())
            ->filter(fn (array $log): bool => str_starts_with($log['query'], 'select count(*) as aggregate from "contacts"'))
            ->map(fn (array $log): string => $log['query'].json_encode($log['bindings']))
            ->values()
            ->all();
    }

    it('counts each rule and the total once when the page renders', function () {
        DB::enableQueryLog();

        ruleEngine($this->countedSegment);

        $queries = ruleEngineCountQueries();

        expect(count($queries))->toBeLessThanOrEqual(4)
            ->and($queries)->toBe(array_values(array_unique($queries)));
    });

    it('does not recount when another rule is selected', function () {
        $page = ruleEngine($this->countedSegment);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $page->call('selectRule', 'rule-2');

        expect(ruleEngineCountQueries())->toBe([]);
    });

    it('only counts the previewed condition while the condition modal is filled', function () {
        $page = ruleEngine($this->countedSegment);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $page->mountAction(TestAction::make('addCondition')->arguments(['rule' => 'rule-1']))
            ->fillForm(['type' => 'attribute'])
            ->fillForm(['type' => 'attribute', 'field' => 'name'])
            ->fillForm(['type' => 'attribute', 'field' => 'name', 'operator' => 'contains', 'text_value' => 'Alice']);

        expect(count(ruleEngineCountQueries()))->toBeLessThanOrEqual(1);
    });

    it('shows the new counts at once after a rule is edited', function () {
        $page = ruleEngine($this->countedSegment);
        $rule = $this->countedSegment->workingRules()->first();

        expect($page->instance()->ruleMatchCount($rule))->toBe(7)
            ->and($page->instance()->segmentMatchCount())->toBe(10);

        $page->callAction(
            TestAction::make('addCondition')->arguments(['rule' => 'rule-1']),
            ['type' => 'attribute', 'field' => 'name', 'operator' => 'contains', 'text_value' => 'Alice'],
        );

        $edited = $this->countedSegment->fresh()->workingRules()->first();

        expect($page->instance()->ruleMatchCount($edited))->toBe(5)
            ->and($page->instance()->segmentMatchCount())->toBe(8);
    });
});

describe('conditions', function () {
    it('adds a condition of every type through the modal', function (Closure $formData, Closure $expectedValue, SegmentConditionType $type, SegmentOperator $operator) {
        $segment = ruleEngineSegment([new SegmentRuleData('rule-1', 'Rule', [])]);
        $data = $formData->call($this);

        ruleEngine($segment)
            ->callAction(TestAction::make('addCondition')->arguments(['rule' => 'rule-1']), $data)
            ->assertHasNoActionErrors();

        $condition = $segment->fresh()->workingRules()->sole()->conditions[0];

        expect($condition->type)->toBe($type)
            ->and($condition->operator)->toBe($operator)
            ->and($condition->value)->toEqual($expectedValue->call($this))
            ->and($segment->fieldCatalog()->isConditionComplete($condition))->toBeTrue();
    })->with([
        'text attribute' => [
            fn () => ['type' => 'attribute', 'field' => 'name', 'operator' => 'contains', 'text_value' => 'ali'],
            fn () => ['value' => 'ali'],
            SegmentConditionType::Attribute, SegmentOperator::Contains,
        ],
        'email domain' => [
            fn () => ['type' => 'attribute', 'field' => 'email', 'operator' => 'is_from_domain', 'text_value' => 'acme.com'],
            fn () => ['value' => 'acme.com'],
            SegmentConditionType::Attribute, SegmentOperator::IsFromDomain,
        ],
        'blank attribute' => [
            fn () => ['type' => 'attribute', 'field' => 'phone', 'operator' => 'is_blank'],
            fn () => [],
            SegmentConditionType::Attribute, SegmentOperator::IsBlank,
        ],
        'status any of' => [
            fn () => ['type' => 'attribute', 'field' => 'status', 'operator' => 'is_any_of', 'values' => ['lead', 'partner']],
            fn () => ['values' => ['lead', 'partner']],
            SegmentConditionType::Attribute, SegmentOperator::IsAnyOf,
        ],
        'created within last days' => [
            fn () => ['type' => 'attribute', 'field' => 'created_at', 'operator' => 'within_last_days', 'days' => 30],
            fn () => ['days' => 30],
            SegmentConditionType::Attribute, SegmentOperator::WithinLastDays,
        ],
        'created between' => [
            fn () => ['type' => 'attribute', 'field' => 'created_at', 'operator' => 'between', 'date_value' => '2026-01-01', 'date_value_to' => '2026-02-01'],
            fn () => ['value' => '2026-01-01', 'value_to' => '2026-02-01'],
            SegmentConditionType::Attribute, SegmentOperator::Between,
        ],
        'number custom field' => [
            fn () => ['type' => 'custom_field', 'field' => CustomField::factory()->for($this->org)->create(['type' => 'number'])->key, 'operator' => 'greater_than', 'number_value' => 5],
            fn () => ['value' => 5],
            SegmentConditionType::CustomField, SegmentOperator::GreaterThan,
        ],
        'date custom field day and month' => [
            fn () => ['type' => 'custom_field', 'field' => CustomField::factory()->for($this->org)->create(['type' => 'date'])->key, 'operator' => 'day_and_month_is', 'month' => 3, 'day' => 14],
            fn () => ['month' => 3, 'day' => 14],
            SegmentConditionType::CustomField, SegmentOperator::DayAndMonthIs,
        ],
        'select custom field' => [
            fn () => ['type' => 'custom_field', 'field' => CustomField::factory()->for($this->org)->select()->create()->key, 'operator' => 'is', 'option_value' => 'opt2'],
            fn () => ['value' => 'opt2'],
            SegmentConditionType::CustomField, SegmentOperator::Is,
        ],
        'multi-select custom field' => [
            fn () => ['type' => 'custom_field', 'field' => CustomField::factory()->for($this->org)->multiselect()->create()->key, 'operator' => 'contains_all_of', 'values' => ['tag1', 'tag3']],
            fn () => ['values' => ['tag1', 'tag3']],
            SegmentConditionType::CustomField, SegmentOperator::ContainsAllOf,
        ],
        'tags' => [
            fn () => ['type' => 'tags', 'operator' => 'has_any_of', 'values' => [($this->tag = Tag::factory()->for($this->org)->create())->id]],
            fn () => ['values' => [$this->tag->id]],
            SegmentConditionType::Tags, SegmentOperator::HasAnyOf,
        ],
        'no tags' => [
            fn () => ['type' => 'tags', 'operator' => 'has_no_tags'],
            fn () => [],
            SegmentConditionType::Tags, SegmentOperator::HasNoTags,
        ],
        'company' => [
            fn () => ['type' => 'company', 'operator' => 'belongs_to_any_of', 'values' => [($this->company = Company::factory()->for($this->org)->create())->id]],
            fn () => ['values' => [$this->company->id]],
            SegmentConditionType::Company, SegmentOperator::BelongsToAnyOf,
        ],
        'company type' => [
            fn () => ['type' => 'company', 'operator' => 'company_type_is_any_of', 'values' => [($this->companyType = CompanyType::factory()->for($this->org)->create())->id]],
            fn () => ['values' => [$this->companyType->id]],
            SegmentConditionType::Company, SegmentOperator::CompanyTypeIsAnyOf,
        ],
        'activity' => [
            fn () => ['type' => 'activity', 'operator' => 'has_had_activity', 'activity_types' => ['call', 'email'], 'outcome' => 'positive', 'days' => 14],
            fn () => ['activity_types' => [ActivityType::Call->value, ActivityType::Email->value], 'outcome' => ActivityOutcome::Positive->value, 'days' => 14],
            SegmentConditionType::Activity, SegmentOperator::HasHadActivity,
        ],
        'activity ever' => [
            fn () => ['type' => 'activity', 'operator' => 'has_not_had_activity'],
            fn () => [],
            SegmentConditionType::Activity, SegmentOperator::HasNotHadActivity,
        ],
        'last activity' => [
            fn () => ['type' => 'activity', 'operator' => 'last_activity_more_than_days_ago', 'days' => 90],
            fn () => ['days' => 90],
            SegmentConditionType::Activity, SegmentOperator::LastActivityMoreThanDaysAgo,
        ],
        'deal' => [
            fn () => ['type' => 'deal', 'operator' => 'has_deal', 'deal_statuses' => ['won'], 'deal_stages' => ['won'], 'min_value' => 1000],
            fn () => ['deal_statuses' => [DealStatus::Won->value], 'deal_stages' => [DealStage::Won->value], 'min_value' => 1000],
            SegmentConditionType::Deal, SegmentOperator::HasDeal,
        ],
    ]);

    it('validates the inputs required by the operator', function (array $data, array $errors) {
        ruleEngine(ruleEngineSegment([new SegmentRuleData('rule-1', 'Rule', [])]))
            ->callAction(TestAction::make('addCondition')->arguments(['rule' => 'rule-1']), $data)
            ->assertHasActionErrors($errors);
    })->with([
        'missing type' => [[], ['type' => 'required']],
        'missing field' => [['type' => 'attribute'], ['field' => 'required']],
        'missing operator' => [['type' => 'attribute', 'field' => 'name'], ['operator' => 'required']],
        'missing text value' => [['type' => 'attribute', 'field' => 'name', 'operator' => 'is'], ['text_value' => 'required']],
        'missing range end' => [['type' => 'attribute', 'field' => 'created_at', 'operator' => 'between', 'date_value' => '2026-01-01'], ['date_value_to' => 'required']],
        'missing values' => [['type' => 'tags', 'operator' => 'has_all_of'], ['values' => 'required']],
        'missing days' => [['type' => 'activity', 'operator' => 'last_activity_more_than_days_ago'], ['days' => 'required']],
        'non-positive days' => [['type' => 'attribute', 'field' => 'created_at', 'operator' => 'within_last_days', 'days' => 0], ['days']],
    ]);

    it('only offers the operators of the selected field', function () {
        ruleEngine(ruleEngineSegment([new SegmentRuleData('rule-1', 'Rule', [])]))
            ->mountAction(TestAction::make('addCondition')->arguments(['rule' => 'rule-1']))
            ->fillForm(['type' => 'attribute', 'field' => 'status'])
            ->assertFormFieldExists('operator', fn ($field): bool => array_keys($field->getOptions()) === ['is', 'is_not', 'is_any_of', 'is_none_of', 'is_blank', 'is_not_blank']);
    });

    it('tells that negative operators also match contacts with no value', function () {
        $score = CustomField::factory()->for($this->org)->create(['name' => 'Score', 'type' => 'number']);
        $hint = 'Contacts with no value for this field also match.';

        ruleEngine(ruleEngineSegment([new SegmentRuleData('rule-1', 'Rule', [])]))
            ->mountAction(TestAction::make('addCondition')->arguments(['rule' => 'rule-1']))
            ->fillForm(['type' => 'custom_field', 'field' => $score->key])
            ->assertMountedActionModalDontSee($hint)
            ->fillForm(['operator' => 'not_equal_to'])
            ->assertMountedActionModalSee($hint)
            ->fillForm(['operator' => 'greater_than'])
            ->assertMountedActionModalDontSee($hint);
    });

    it('resets the dependent fields when the type changes', function () {
        ruleEngine(ruleEngineSegment([new SegmentRuleData('rule-1', 'Rule', [])]))
            ->mountAction(TestAction::make('addCondition')->arguments(['rule' => 'rule-1']))
            ->fillForm(['type' => 'attribute', 'field' => 'name', 'operator' => 'is', 'text_value' => 'Bob'])
            ->fillForm(['type' => 'tags'])
            ->assertSchemaStateSet(['field' => null, 'operator' => null, 'text_value' => null]);
    });

    it('previews how many contacts the condition matches', function () {
        Contact::factory()->for($this->org)->count(3)->create(['status' => ContactStatus::Lead]);

        ruleEngine(ruleEngineSegment([new SegmentRuleData('rule-1', 'Rule', [])]))
            ->mountAction(TestAction::make('addCondition')->arguments(['rule' => 'rule-1']))
            ->assertMountedActionModalSee('Complete the condition to preview how many contacts it matches.')
            ->fillForm(['type' => 'attribute', 'field' => 'status', 'operator' => 'is', 'option_value' => 'lead'])
            ->assertMountedActionModalSee('This condition matches 3 contacts.');
    });

    it('refuses too many days in the condition modal and does not preview them', function () {
        $segment = ruleEngineSegment([new SegmentRuleData('rule-1', 'Rule', [])]);
        $data = ['type' => 'attribute', 'field' => 'created_at', 'operator' => 'within_last_days', 'days' => SegmentFieldCatalog::MAX_DAYS + 1];

        ruleEngine($segment)
            ->mountAction(TestAction::make('addCondition')->arguments(['rule' => 'rule-1']))
            ->fillForm(['type' => 'attribute', 'field' => 'created_at', 'operator' => 'within_last_days', 'days' => 999999999])
            ->assertMountedActionModalSee('Complete the condition to preview how many contacts it matches.');

        ruleEngine($segment)
            ->callAction(TestAction::make('addCondition')->arguments(['rule' => 'rule-1']), $data)
            ->assertHasActionErrors(['days' => ['max']]);

        expect($segment->fresh()->workingRules()->sole()->conditions)->toBe([]);
    });

    it('edits a condition, pre-filling the modal', function () {
        $segment = ruleEngineSegment([leadRule()]);

        ruleEngine($segment)
            ->mountAction(TestAction::make('editCondition')->arguments(['rule' => 'rule-1', 'condition' => 'condition-1']))
            ->assertSchemaStateSet(['type' => SegmentConditionType::Attribute, 'field' => 'status', 'operator' => 'is', 'option_value' => 'lead'])
            ->fillForm(['option_value' => 'partner'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $condition = $segment->fresh()->workingRules()->sole()->conditions[0];

        expect($condition->id)->toBe('condition-1')
            ->and($condition->value)->toBe(['value' => 'partner']);
    });

    it('deletes a condition', function () {
        $segment = ruleEngineSegment([leadRule()]);

        ruleEngine($segment)
            ->callAction(TestAction::make('deleteCondition')->arguments(['rule' => 'rule-1', 'condition' => 'condition-1']));

        expect($segment->fresh()->workingRules()->sole()->conditions)->toBe([]);
    });
});

describe('publishing and drafts', function () {
    it('publishes a complete segment', function () {
        Queue::fake();
        $segment = ruleEngineSegment([leadRule()]);

        ruleEngine($segment)
            ->assertActionEnabled('publish')
            ->callAction('publish')
            ->assertRedirect(SegmentResource::getUrl('view', ['record' => $segment]));

        expect($segment->fresh()->is_published)->toBeTrue();
        Queue::assertPushed(SyncSegmentMembership::class);
    });

    it('cannot publish a segment with an empty rule', function () {
        ruleEngine(ruleEngineSegment([leadRule(), new SegmentRuleData('rule-2', 'Empty', [])]))
            ->assertActionDisabled('publish');
    });

    it('edits a published segment as a draft without changing its members', function () {
        $segment = ruleEngineSegment([leadRule()], ['is_published' => true]);
        $partner = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);

        ruleEngine($segment)
            ->assertActionHidden('publish')
            ->assertActionHidden('cancelChanges')
            ->assertActionDisabled('saveChanges')
            ->callAction(TestAction::make('editCondition')->arguments(['rule' => 'rule-1', 'condition' => 'condition-1']), ['option_value' => 'partner'])
            ->assertActionVisible('cancelChanges')
            ->assertActionEnabled('saveChanges');

        $segment->refresh();

        expect($segment->publishedRules()->sole()->conditions[0]->value)->toBe(['value' => 'lead'])
            ->and($segment->workingRules()->sole()->conditions[0]->value)->toBe(['value' => 'partner'])
            ->and($segment->hasPendingChanges())->toBeTrue()
            ->and($segment->contacts()->whereKey($partner)->exists())->toBeFalse();
    });

    it('saves the draft and recomputes the members', function () {
        $segment = ruleEngineSegment([leadRule()], ['is_published' => true]);
        $partner = Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);
        Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);

        ruleEngine($segment)
            ->callAction(TestAction::make('editCondition')->arguments(['rule' => 'rule-1', 'condition' => 'condition-1']), ['option_value' => 'partner'])
            ->callAction('saveChanges')
            ->assertRedirect(SegmentResource::getUrl('view', ['record' => $segment]));

        $segment->refresh();

        expect($segment->draft_rules)->toBeNull()
            ->and($segment->publishedRules()->sole()->conditions[0]->value)->toBe(['value' => 'partner'])
            ->and($segment->is_syncing)->toBeFalse()
            ->and($segment->contacts()->pluck('contacts.id')->all())->toBe([$partner->id]);
    });

    it('cancels the draft', function () {
        $segment = ruleEngineSegment([leadRule()], ['is_published' => true]);

        ruleEngine($segment)
            ->callAction('createRule', ['name' => 'Temporary'])
            ->assertSee('Temporary')
            ->callAction('cancelChanges')
            ->assertSet('selectedRuleId', 'rule-1')
            ->assertDontSee('Temporary');

        $segment->refresh();

        expect($segment->draft_rules)->toBeNull()
            ->and($segment->workingRules()->pluck('name')->all())->toBe(['Leads']);
    });

    it('treats a draft identical to the published rules as unchanged', function () {
        $segment = ruleEngineSegment([leadRule()], ['is_published' => true]);

        ruleEngine($segment)
            ->callAction(TestAction::make('editCondition')->arguments(['rule' => 'rule-1', 'condition' => 'condition-1']), ['option_value' => 'lead'])
            ->assertActionDisabled('saveChanges')
            ->assertActionHidden('cancelChanges');
    });

    it('renames the segment', function () {
        $segment = ruleEngineSegment();

        ruleEngine($segment)
            ->callAction('rename', ['name' => 'Renamed'])
            ->assertHasNoActionErrors();

        expect($segment->fresh()->name)->toBe('Renamed');
    });
});

it('keeps a soft-deleted custom field selectable when editing a condition saved before it was deleted', function () {
    $field = CustomField::factory()->for($this->org)->text()->create(['name' => 'Industry']);
    $field->delete();
    $segment = ruleEngineSegment([new SegmentRuleData('rule-1', 'Rule', [
        new SegmentConditionData('condition-1', SegmentConditionType::CustomField, $field->key, SegmentOperator::Contains, ['value' => 'tech']),
    ])]);

    ruleEngine($segment)
        ->callAction(TestAction::make('editCondition')->arguments(['rule' => 'rule-1', 'condition' => 'condition-1']), ['text_value' => 'fin'])
        ->assertHasNoActionErrors();

    expect($segment->fresh()->workingRules()->sole()->conditions[0]->value)->toBe(['value' => 'fin']);
});

it('only renders the draft buttons when they apply', function (array $attributes, bool $showsCancel, bool $showsSave) {
    $segment = ruleEngineSegment([leadRule()], $attributes);

    $page = ruleEngine($segment);

    $showsCancel ? $page->assertSeeHtml('Cancel Changes') : $page->assertDontSeeHtml('Cancel Changes');
    $showsSave ? $page->assertSeeHtml('Save Changes') : $page->assertDontSeeHtml('Save Changes');
    $page->assertSeeHtml('Create a Rule');
})->with([
    'never published' => [[], false, false],
    'published without changes' => [['is_published' => true], false, true],
    'published with a draft' => [['is_published' => true, 'draft_rules' => [['id' => 'rule-1', 'name' => 'Changed', 'conditions' => []]]], true, true],
]);
