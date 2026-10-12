<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Jobs\SyncSegmentMembership;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->org = Organization::factory()->create(['timezone' => 'UTC']);

    $this->createdDate = fn (SegmentOperator $operator, array $value): SegmentConditionData => SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, $operator, $value);

    $this->segmentWith = fn (SegmentConditionData $condition, bool $published = true, array $attributes = []): Segment => Segment::factory()->for($this->org)->state(['is_published' => $published])->withRules([
        new SegmentRuleData('rule-1', 'Rule', [$condition]),
    ])->create($attributes);

    $this->pushedSegmentIds = fn (): array => Queue::pushed(SyncSegmentMembership::class)->map->segmentId->sort()->values()->all();
});

it('re-syncs the segments with an absolute Created Date condition', function (SegmentOperator $operator, array $value) {
    $segment = ($this->segmentWith)(($this->createdDate)($operator, $value));
    Queue::fake();

    $this->org->update(['timezone' => 'Europe/Paris']);

    expect(($this->pushedSegmentIds)())->toBe([$segment->id]);
})->with([
    'is on' => [SegmentOperator::On, ['value' => '2026-03-16']],
    'is before' => [SegmentOperator::Before, ['value' => '2026-03-16']],
    'is after' => [SegmentOperator::After, ['value' => '2026-03-16']],
    'is between' => [SegmentOperator::Between, ['value' => '2026-03-01', 'value_to' => '2026-03-16']],
    'month is' => [SegmentOperator::MonthIs, ['month' => 3]],
    'day and month is' => [SegmentOperator::DayAndMonthIs, ['month' => 3, 'day' => 16]],
    'is within the last days' => [SegmentOperator::WithinLastDays, ['days' => 7]],
]);

it('does not re-sync the segments that do not depend on the timezone', function () {
    $renewal = CustomField::factory()->for($this->org)->create(['name' => 'Renewal', 'type' => 'date']);
    $tag = Tag::factory()->for($this->org)->create(['name' => 'VIP']);
    ($this->segmentWith)(SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]));
    ($this->segmentWith)(SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Contains, ['value' => 'Ann']));
    ($this->segmentWith)(SegmentConditionData::make(SegmentConditionType::CustomField, $renewal->key, SegmentOperator::On, ['value' => '2026-03-16']));
    Queue::fake();

    $this->org->update(['timezone' => 'Europe/Paris']);

    Queue::assertNothingPushed();
});

it('ignores unpublished segments and drafts', function () {
    ($this->segmentWith)(($this->createdDate)(SegmentOperator::On, ['value' => '2026-03-16']), published: false);
    $tag = Tag::factory()->for($this->org)->create();
    ($this->segmentWith)(
        SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
        attributes: ['draft_rules' => [(new SegmentRuleData('rule-1', 'Rule', [($this->createdDate)(SegmentOperator::On, ['value' => '2026-03-16'])]))->toArray()]],
    );
    Queue::fake();

    $this->org->update(['timezone' => 'Europe/Paris']);

    Queue::assertNothingPushed();
});

it('does nothing when another field of the organization changes', function () {
    ($this->segmentWith)(($this->createdDate)(SegmentOperator::On, ['value' => '2026-03-16']));
    Queue::fake();

    $this->org->update(['name' => 'Renamed']);

    Queue::assertNothingPushed();
});

it('does not re-sync the segments of another organization', function () {
    $other = Organization::factory()->create();
    Segment::factory()->for($other)->published()->withRules([
        new SegmentRuleData('rule-1', 'Rule', [($this->createdDate)(SegmentOperator::On, ['value' => '2026-03-16'])]),
    ])->create();
    Queue::fake();

    $this->org->update(['timezone' => 'Europe/Paris']);

    Queue::assertNothingPushed();
});

it('makes the membership follow the new timezone', function () {
    $contact = Contact::factory()->for($this->org)->create(['created_at' => '2026-03-15 23:30:00']);
    $segment = ($this->segmentWith)(($this->createdDate)(SegmentOperator::On, ['value' => '2026-03-16']));
    SyncSegmentMembership::dispatchSync($segment->id);

    expect(DB::table('contact_segment')->where('segment_id', $segment->id)->count())->toBe(0);

    $this->org->update(['timezone' => 'Europe/Paris']);

    expect(DB::table('contact_segment')->where('segment_id', $segment->id)->pluck('contact_id')->all())->toBe([$contact->id]);
});

it('never throws for an incomplete attribute condition', function (?string $field) {
    $condition = SegmentConditionData::make(SegmentConditionType::Attribute, $field, SegmentOperator::On, []);

    expect($condition->dependsOnTimezone())->toBeFalse();
})->with([null, 'not_an_attribute', '']);

it('knows which conditions depend on the timezone', function (SegmentConditionType $type, ?string $field, SegmentOperator $operator, bool $expected) {
    expect(SegmentConditionData::make($type, $field, $operator, [])->dependsOnTimezone())->toBe($expected);
})->with([
    'created date is on' => [SegmentConditionType::Attribute, 'created_at', SegmentOperator::On, true],
    'created date is blank' => [SegmentConditionType::Attribute, 'created_at', SegmentOperator::IsBlank, true],
    'text attribute' => [SegmentConditionType::Attribute, 'name', SegmentOperator::Contains, false],
    'select attribute' => [SegmentConditionType::Attribute, 'status', SegmentOperator::Is, false],
    'custom field date is on' => [SegmentConditionType::CustomField, 'cf_x', SegmentOperator::On, false],
    'custom field date within the last days' => [SegmentConditionType::CustomField, 'cf_x', SegmentOperator::WithinLastDays, true],
    'tags' => [SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, false],
    'custom field that is not an attribute' => [SegmentConditionType::CustomField, 'created_at', SegmentOperator::On, false],
]);

it('only looks at the published rules of a segment, not at its draft', function () {
    $tag = Tag::factory()->for($this->org)->create();
    $segment = ($this->segmentWith)(
        SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]),
        attributes: ['draft_rules' => [(new SegmentRuleData('rule-1', 'Rule', [($this->createdDate)(SegmentOperator::On, ['value' => '2026-03-16'])]))->toArray()]],
    )->fresh();

    expect($segment->draft_rules)->not->toBeNull()
        ->and($segment->dependsOnTimezone())->toBeFalse();

    $segment->update(['rules' => $segment->draft_rules]);

    expect($segment->fresh()->dependsOnTimezone())->toBeTrue();
});
