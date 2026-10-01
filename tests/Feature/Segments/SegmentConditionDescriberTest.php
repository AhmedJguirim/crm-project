<?php

use App\Data\Segments\SegmentConditionData;
use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\DealStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Tag;
use App\Services\Segments\SegmentConditionDescriber;
use App\Services\Segments\SegmentFieldCatalog;

function describeCondition(Organization $organization, SegmentConditionData $condition): string
{
    $describer = new SegmentConditionDescriber(SegmentFieldCatalog::forOrganization($organization->id));

    return strip_tags($describer->describe($condition)->toHtml());
}

beforeEach(function () {
    $this->org = Organization::factory()->create();
});

it('describes conditions as readable sentences', function (Closure $condition, string $expected) {
    expect(describeCondition($this->org, $condition->call($this)))->toBe($expected);
})->with([
    'text' => [
        fn () => SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Email->value, SegmentOperator::IsFromDomain, ['value' => 'acme.com']),
        'If the contact Email is from domain acme.com',
    ],
    'blank' => [
        fn () => SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Phone->value, SegmentOperator::IsBlank),
        'If the contact Phone is blank',
    ],
    'enum select label' => [
        fn () => SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::IsAnyOf, ['values' => [ContactStatus::Lead->value, ContactStatus::ActiveClient->value]]),
        'If the contact Status is any of Lead, Active Client',
    ],
    'relative date' => [
        fn () => SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::MoreThanDaysAgo, ['days' => 30]),
        'If the contact Created Date is more than 30 days ago',
    ],
    'range' => [
        fn () => SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::Between, ['value' => '2026-01-01', 'value_to' => '2026-02-01']),
        'If the contact Created Date is between 2026-01-01 and 2026-02-01',
    ],
    'custom field option label' => [
        fn () => SegmentConditionData::make(SegmentConditionType::CustomField, CustomField::factory()->for($this->org)->select()->create(['name' => 'Plan'])->key, SegmentOperator::Is, ['value' => 'opt2']),
        'If the contact Plan is Option 2',
    ],
    'day and month' => [
        fn () => SegmentConditionData::make(SegmentConditionType::CustomField, CustomField::factory()->for($this->org)->create(['name' => 'Birthday', 'type' => 'date'])->key, SegmentOperator::DayAndMonthIs, ['month' => 3, 'day' => 14]),
        'If the contact Birthday day and month is 14 March',
    ],
    'tags' => [
        fn () => SegmentConditionData::make(SegmentConditionType::Tags, null, SegmentOperator::HasAllOf, ['values' => [Tag::factory()->for($this->org)->create(['name' => 'VIP'])->id, 999999]]),
        'If the contact has all of VIP, 999999 (deleted)',
    ],
    'activity' => [
        fn () => SegmentConditionData::make(SegmentConditionType::Activity, null, SegmentOperator::HasHadActivity, ['activity_types' => [ActivityType::Call->value], 'outcome' => ActivityOutcome::Positive->value, 'days' => 7]),
        'If the contact has had an activity of type Call with outcome Positive in the last 7 days',
    ],
    'activity ever' => [
        fn () => SegmentConditionData::make(SegmentConditionType::Activity, null, SegmentOperator::HasNotHadActivity),
        'If the contact has not had an activity ever',
    ],
    'deal' => [
        fn () => SegmentConditionData::make(SegmentConditionType::Deal, null, SegmentOperator::HasDeal, ['deal_statuses' => [DealStatus::Won->value], 'min_value' => 5000]),
        'If the contact has a deal with status Won worth at least 5,000',
    ],
    'deleted custom field' => [
        fn () => SegmentConditionData::make(SegmentConditionType::CustomField, 'cf_missing', SegmentOperator::IsBlank),
        'If the contact Unknown field (deleted) is blank',
    ],
]);

it('escapes user content', function () {
    $condition = SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is, ['value' => '<script>alert(1)</script>']);
    $describer = new SegmentConditionDescriber(SegmentFieldCatalog::forOrganization($this->org->id));

    expect($describer->describe($condition)->toHtml())
        ->not->toContain('<script>')
        ->toContain('&lt;script&gt;');
});

it('marks soft-deleted custom fields', function () {
    $field = CustomField::factory()->for($this->org)->text()->create(['name' => 'Industry']);
    $field->delete();

    expect(describeCondition($this->org, SegmentConditionData::make(SegmentConditionType::CustomField, $field->key, SegmentOperator::IsBlank)))
        ->toBe('If the contact Industry (deleted) is blank');
});
