<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Models\CustomField;
use App\Models\Organization;
use App\Services\Segments\SegmentFieldCatalog;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->catalog = SegmentFieldCatalog::forOrganization($this->org->id);
});

function catalogCondition(SegmentConditionType $type, ?string $field, SegmentOperator $operator, array $value = []): SegmentConditionData
{
    return SegmentConditionData::make($type, $field, $operator, $value);
}

it('accepts complete conditions', function (Closure $condition) {
    expect($this->catalog->isConditionComplete($condition->call($this)))->toBeTrue();
})->with([
    'no value needed' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::Phone->value, SegmentOperator::IsBlank),
    'text' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is, ['value' => 'Bob']),
    'date' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::On, ['value' => '2026-02-28']),
    'range' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::Between, ['value' => '2026-01-01', 'value_to' => '2026-02-01']),
    'numeric string' => fn () => catalogCondition(SegmentConditionType::CustomField, CustomField::factory()->for($this->org)->create(['type' => 'number'])->key, SegmentOperator::GreaterThan, ['value' => '-1.5']),
    'day and month bounds' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::DayAndMonthIs, ['month' => 12, 'day' => 31]),
    'activity without period' => fn () => catalogCondition(SegmentConditionType::Activity, null, SegmentOperator::HasHadActivity),
    'deal without criteria' => fn () => catalogCondition(SegmentConditionType::Deal, null, SegmentOperator::HasDeal),
]);

it('rejects incomplete or invalid conditions', function (Closure $condition) {
    expect($this->catalog->isConditionComplete($condition->call($this)))->toBeFalse();
})->with([
    'unknown attribute' => fn () => catalogCondition(SegmentConditionType::Attribute, 'birthday', SegmentOperator::IsBlank),
    'unknown custom field' => fn () => catalogCondition(SegmentConditionType::CustomField, 'cf_missing', SegmentOperator::IsBlank),
    'operator not offered for the field' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::HasAnyOf, ['values' => [1]]),
    'operator of another condition type' => fn () => catalogCondition(SegmentConditionType::Tags, null, SegmentOperator::IsBlank),
    'blank text' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Contains, ['value' => '  ']),
    'array as a single value' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is, ['value' => ['Bob']]),
    'invalid date' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::On, ['value' => 'not a date']),
    'range missing its end' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::Between, ['value' => '2026-01-01']),
    'non-numeric number' => fn () => catalogCondition(SegmentConditionType::CustomField, CustomField::factory()->for($this->org)->create(['type' => 'number'])->key, SegmentOperator::EqualTo, ['value' => 'ten']),
    'empty multiple values' => fn () => catalogCondition(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => ['', null]]),
    'zero days' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::WithinLastDays, ['days' => 0]),
    'negative days' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::MoreThanDaysAgo, ['days' => -3]),
    'fractional days' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::WithinLastDays, ['days' => '2.5']),
    'month 13' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::MonthIs, ['month' => 13]),
    'day 32' => fn () => catalogCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::DayAndMonthIs, ['month' => 1, 'day' => 32]),
    'last activity without days' => fn () => catalogCondition(SegmentConditionType::Activity, null, SegmentOperator::LastActivityMoreThanDaysAgo),
    'activity with invalid period' => fn () => catalogCondition(SegmentConditionType::Activity, null, SegmentOperator::HasHadActivity, ['days' => 'week']),
    'deal with non-numeric minimum' => fn () => catalogCondition(SegmentConditionType::Deal, null, SegmentOperator::HasDeal, ['min_value' => 'lots']),
]);

it('requires at least one rule, each with at least one complete condition', function () {
    $complete = new SegmentRuleData('r1', 'Complete', [catalogCondition(SegmentConditionType::Attribute, ContactAttribute::Phone->value, SegmentOperator::IsBlank)]);
    $empty = new SegmentRuleData('r2', 'Empty', []);
    $broken = new SegmentRuleData('r3', 'Broken', [
        catalogCondition(SegmentConditionType::Attribute, ContactAttribute::Phone->value, SegmentOperator::IsBlank),
        catalogCondition(SegmentConditionType::CustomField, 'cf_missing', SegmentOperator::IsBlank),
    ]);

    expect($this->catalog->areRulesComplete([]))->toBeFalse()
        ->and($this->catalog->areRulesComplete([$complete]))->toBeTrue()
        ->and($this->catalog->areRulesComplete([$complete, $empty]))->toBeFalse()
        ->and($this->catalog->areRulesComplete([$complete, $broken]))->toBeFalse();
});

it('only knows the custom fields of its organization, including soft-deleted ones', function () {
    $own = CustomField::factory()->for($this->org)->text()->create();
    $trashed = CustomField::factory()->for($this->org)->text()->create();
    $trashed->delete();
    $foreign = CustomField::factory()->text()->create();

    expect($this->catalog->customFields()->keys()->sort()->values()->all())->toBe(collect([$own->key, $trashed->key])->sort()->values()->all())
        ->and($this->catalog->isConditionComplete(catalogCondition(SegmentConditionType::CustomField, $foreign->key, SegmentOperator::IsBlank)))->toBeFalse();
});

it('maps custom field types to the right operators', function (string $type, array $expected) {
    $field = CustomField::factory()->for($this->org)->create(['type' => $type, 'options' => [['label' => 'A', 'value' => 'a']]]);

    expect($this->catalog->fieldKind(catalogCondition(SegmentConditionType::CustomField, $field->key, SegmentOperator::IsBlank))?->value)
        ->toBe($expected[0]);
})->with([
    'text' => ['text', ['text']],
    'text area' => ['textarea', ['text']],
    'url' => ['url', ['text']],
    'phone' => ['phone', ['text']],
    'email' => ['email', ['email']],
    'number' => ['number', ['number']],
    'date' => ['date', ['date']],
    'select' => ['select', ['select']],
    'multi-select' => ['multiselect', ['multiselect']],
]);
