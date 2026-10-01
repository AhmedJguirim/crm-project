<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Enums\SegmentStatus;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Segments\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\Segments\Widgets\SegmentStatsOverview;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function statusRule(string $id, string $name, ContactStatus ...$statuses): SegmentRuleData
{
    return new SegmentRuleData($id, $name, [
        SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::IsAnyOf, [
            'values' => array_map(fn (ContactStatus $status): string => $status->value, $statuses),
        ]),
    ]);
}

it('computes the stats of the segment members', function () {
    $this->travelTo(now()->setDate(2026, 6, 15));
    $segment = Segment::factory()->for($this->org)->published()->create();
    [$recent, $older] = Contact::factory()->for($this->org)->count(2)->create();
    Contact::factory()->for($this->org)->count(2)->create();
    Contact::factory()->for($this->org)->create()->delete();
    $segment->contacts()->attach($recent, ['created_at' => now()->subDays(10), 'updated_at' => now()]);
    $segment->contacts()->attach($older, ['created_at' => now()->subDays(40), 'updated_at' => now()]);

    Livewire::test(SegmentStatsOverview::class, ['record' => $segment])
        ->assertSeeInOrder(['Members', '2', 'Coverage', '50%', 'Of all 4 contacts', 'Joined (30 days)', '1', '1 in the previous 30 days']);
});

it('explains that an unpublished segment has no members yet', function () {
    Livewire::test(SegmentStatsOverview::class, ['record' => Segment::factory()->for($this->org)->create()])
        ->assertSee('Publish the segment to compute its members')
        ->assertSee('0%');
});

it('shows the status of every segment', function (array $attributes, SegmentStatus $expected) {
    $segment = Segment::factory()->for($this->org)->withRules([statusRule('rule-1', 'Leads', ContactStatus::Lead)])->create($attributes);

    Livewire::test(ListSegments::class)
        ->assertTableColumnStateSet('status', $expected, record: $segment);
})->with([
    'never published' => [[], SegmentStatus::Draft],
    'published' => [['is_published' => true], SegmentStatus::Published],
    'syncing' => [['is_published' => true, 'is_syncing' => true], SegmentStatus::Syncing],
    'unsaved changes' => [['is_published' => true, 'draft_rules' => [['id' => 'rule-1', 'name' => 'Changed', 'conditions' => []]]], SegmentStatus::PendingChanges],
]);

it('shows the member count of every segment', function () {
    $segment = Segment::factory()->for($this->org)->published()->create();
    $segment->contacts()->attach(Contact::factory()->for($this->org)->count(3)->create());
    $segment->contacts()->attach($trashed = Contact::factory()->for($this->org)->create());
    $trashed->delete();

    Livewire::test(ListSegments::class)
        ->assertTableColumnStateSet('contacts_count', 3, record: $segment->getKey());
});

it('shows the live match count of every rule and of the whole segment', function () {
    Contact::factory()->for($this->org)->count(2)->create(['status' => ContactStatus::Lead]);
    Contact::factory()->for($this->org)->create(['status' => ContactStatus::Partner]);
    Contact::factory()->for($this->org)->create(['status' => ContactStatus::PastClient]);
    $segment = Segment::factory()->for($this->org)->withRules([
        statusRule('rule-1', 'Leads', ContactStatus::Lead),
        statusRule('rule-2', 'Leads and partners', ContactStatus::Lead, ContactStatus::Partner),
        new SegmentRuleData('rule-3', 'Empty', []),
    ])->create();

    $page = Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()])
        ->assertSeeInOrder(['Leads', '2 matches', 'Leads and partners', '3 matches', 'Empty', 'Incomplete']);

    $rules = $segment->workingRules();

    expect($page->instance()->segmentMatchCount())->toBe(3)
        ->and($page->instance()->ruleMatchCount($rules[0]))->toBe(2)
        ->and($page->instance()->ruleMatchCount($rules[1]))->toBe(3)
        ->and($page->instance()->ruleMatchCount($rules[2]))->toBeNull();
});

it('summarizes the published rules on the segment page, ignoring the unsaved draft', function () {
    $segment = Segment::factory()->for($this->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'Hot leads', [
            SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value]),
            SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Phone->value, SegmentOperator::IsNotBlank),
        ]),
        statusRule('rule-2', 'Partners', ContactStatus::Partner),
    ])->create(['draft_rules' => [['id' => 'rule-9', 'name' => 'Draft only rule', 'conditions' => []]]]);

    Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])
        ->assertSeeInOrder(['Hot leads', 'Status', 'Lead', 'and', 'Phone', 'is not blank', 'or', 'Partners', 'Partner'])
        ->assertDontSee('Draft only rule');
});

it('lists members by most recent join first and can sort by join date', function () {
    $segment = Segment::factory()->for($this->org)->published()->create();
    $contacts = Contact::factory()->for($this->org)->count(3)->create();
    foreach ($contacts as $index => $contact) {
        $segment->contacts()->attach($contact, ['created_at' => now()->subDays(10 - $index), 'updated_at' => now()]);
    }

    Livewire::test(ContactsRelationManager::class, ['ownerRecord' => $segment, 'pageClass' => ViewSegment::class])
        ->assertCanSeeTableRecords($contacts->reverse()->values(), inOrder: true)
        ->sortTable('joined_at')
        ->assertCanSeeTableRecords($contacts, inOrder: true);
});

it('only offers published segments in the contacts segment filter', function () {
    $published = Segment::factory()->for($this->org)->published()->create(['name' => 'Published one']);
    $draft = Segment::factory()->for($this->org)->create(['name' => 'Draft one']);
    $foreign = Segment::factory()->published()->create(['name' => 'Foreign one']);

    Livewire::test(ListContacts::class)
        ->assertFormFieldExists('segments.values', 'tableFiltersForm', fn ($select): bool => $select->getOptions() === [$published->id => 'Published one']);
});

it('only offers records of the organization in the condition modal', function () {
    $tag = Tag::factory()->for($this->org)->create(['name' => 'Own tag']);
    $company = Company::factory()->for($this->org)->create(['name' => 'Own company']);
    $companyType = CompanyType::factory()->for($this->org)->create(['name' => 'Own type']);
    $field = CustomField::factory()->for($this->org)->text()->create(['name' => 'Own field']);
    Tag::factory()->create();
    Company::factory()->create();
    CompanyType::factory()->create();
    CustomField::factory()->text()->create();
    $segment = Segment::factory()->for($this->org)->withRules([new SegmentRuleData('rule-1', 'Rule', [])])->create();
    $action = TestAction::make('addCondition')->arguments(['rule' => 'rule-1']);

    Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()])
        ->mountAction($action)
        ->fillForm(['type' => 'tags', 'operator' => 'has_any_of'])
        ->assertFormFieldExists('values', fn ($select): bool => $select->getOptions() === [$tag->id => 'Own tag'])
        ->fillForm(['type' => 'company', 'operator' => 'belongs_to_any_of'])
        ->assertFormFieldExists('values', fn ($select): bool => $select->getOptions() === [$company->id => 'Own company'])
        ->fillForm(['operator' => 'company_type_is_any_of'])
        ->assertFormFieldExists('values', fn ($select): bool => $select->getOptions() === [$companyType->id => 'Own type'])
        ->fillForm(['type' => 'custom_field'])
        ->assertFormFieldExists('field', fn ($select): bool => $select->getOptions() === [$field->key => 'Own field']);
});
