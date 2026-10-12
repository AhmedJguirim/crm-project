<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Models\Contact;
use App\Models\Organization;
use App\Services\Segments\SegmentCountCache;
use App\Services\Segments\SegmentQueryBuilder;
use Illuminate\Support\Facades\DB;

function countCacheStatusCondition(ContactStatus $status): SegmentConditionData
{
    return SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => $status->value]);
}

function countCacheFor(Organization $organization): SegmentCountCache
{
    return new SegmentCountCache(SegmentQueryBuilder::forOrganization($organization->id));
}

/** @return array<int, string> */
function contactCountQueries(): array
{
    return collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_starts_with($query, 'select count(*) as aggregate from "contacts"'))
        ->values()
        ->all();
}

beforeEach(function () {
    $this->org = Organization::factory()->create();
    Contact::factory()->for($this->org)->count(3)->create(['status' => ContactStatus::Lead]);
    Contact::factory()->for($this->org)->count(2)->create(['status' => ContactStatus::Partner]);
    DB::enableQueryLog();
});

it('counts the same conditions once within the cache lifetime', function () {
    $rules = [new SegmentRuleData('rule-1', 'Leads', [countCacheStatusCondition(ContactStatus::Lead)])];

    $first = countCacheFor($this->org)->count($rules);
    $second = countCacheFor($this->org)->count($rules);

    expect([$first, $second])->toBe([3, 3])
        ->and(contactCountQueries())->toHaveCount(1);
});

it('also remembers a count within one instance without asking the cache again', function () {
    $cache = countCacheFor($this->org);

    $cache->countConditions([countCacheStatusCondition(ContactStatus::Lead)]);
    $cache->countConditions([countCacheStatusCondition(ContactStatus::Lead)]);

    expect(contactCountQueries())->toHaveCount(1);
});

it('counts again when a condition changes', function () {
    expect(countCacheFor($this->org)->countConditions([countCacheStatusCondition(ContactStatus::Lead)]))->toBe(3)
        ->and(countCacheFor($this->org)->countConditions([countCacheStatusCondition(ContactStatus::Partner)]))->toBe(2)
        ->and(contactCountQueries())->toHaveCount(2);
});

it('shares the cache between identical conditions of different rules', function () {
    $first = new SegmentRuleData('rule-1', 'First', [countCacheStatusCondition(ContactStatus::Lead)]);
    $second = new SegmentRuleData('rule-2', 'Second', [countCacheStatusCondition(ContactStatus::Lead)]);

    $counts = [countCacheFor($this->org)->countConditions($first->conditions), countCacheFor($this->org)->countConditions($second->conditions)];

    expect($counts)->toBe([3, 3])
        ->and(contactCountQueries())->toHaveCount(1);
});

it('does not share counts between organizations', function () {
    $other = Organization::factory()->create();
    Contact::factory()->for($other)->count(7)->create(['status' => ContactStatus::Lead]);
    $conditions = [countCacheStatusCondition(ContactStatus::Lead)];

    expect(countCacheFor($this->org)->countConditions($conditions))->toBe(3)
        ->and(countCacheFor($other)->countConditions($conditions))->toBe(7);
});

it('counts again after the cache lifetime', function () {
    $conditions = [countCacheStatusCondition(ContactStatus::Lead)];

    countCacheFor($this->org)->countConditions($conditions);
    $this->travel(SegmentCountCache::TTL_SECONDS + 1)->seconds();
    Contact::factory()->for($this->org)->create(['status' => ContactStatus::Lead]);

    expect(countCacheFor($this->org)->countConditions($conditions))->toBe(4)
        ->and(contactCountQueries())->toHaveCount(2);
});
