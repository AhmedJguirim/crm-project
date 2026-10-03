<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ActivityType;
use App\Enums\ContactAttribute;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Organization;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 3, 15)->setTime(23, 30));

    $this->org = Organization::factory()->timezone('Europe/Paris')->create();
    app(TenantContext::class)->set($this->org->id);

    $this->matches = fn (SegmentConditionData $condition, ?Organization $organization = null): array => SegmentQueryBuilder::forOrganization(($organization ?? $this->org)->id)
        ->matching([new SegmentRuleData('rule-1', 'Rule', [$condition])])
        ->orderBy('name')
        ->pluck('name')
        ->all();

    $this->createdAt = fn (SegmentOperator $operator, array $value): SegmentConditionData => SegmentConditionData::make(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, $operator, $value);

    $this->inTimezone = fn (string $timezone) => $this->org->update(['timezone' => $timezone]);
});

describe('the created date', function () {
    it('is the date in the timezone of the organization', function () {
        Contact::factory()->for($this->org)->create(['name' => 'A', 'created_at' => '2026-03-15 23:10:00']);

        expect(($this->matches)(($this->createdAt)(SegmentOperator::On, ['value' => '2026-03-16'])))->toBe(['A'])
            ->and(($this->matches)(($this->createdAt)(SegmentOperator::On, ['value' => '2026-03-15'])))->toBe([]);

        ($this->inTimezone)('UTC');

        expect(($this->matches)(($this->createdAt)(SegmentOperator::On, ['value' => '2026-03-15'])))->toBe(['A']);
    });

    it('counts "within the last days" from the local today', function () {
        Contact::factory()->for($this->org)->create(['name' => 'A', 'created_at' => '2026-03-14 22:30:00']);

        expect(($this->matches)(($this->createdAt)(SegmentOperator::WithinLastDays, ['days' => 1])))->toBe([]);

        ($this->inTimezone)('UTC');

        expect(($this->matches)(($this->createdAt)(SegmentOperator::WithinLastDays, ['days' => 1])))->toBe(['A']);
    });

    it('counts "more than days ago" from the local today', function () {
        Contact::factory()->for($this->org)->create(['name' => 'A', 'created_at' => '2026-03-14 22:30:00']);

        expect(($this->matches)(($this->createdAt)(SegmentOperator::MoreThanDaysAgo, ['days' => 1])))->toBe(['A']);

        ($this->inTimezone)('UTC');

        expect(($this->matches)(($this->createdAt)(SegmentOperator::MoreThanDaysAgo, ['days' => 1])))->toBe([]);
    });

    it('uses the local date for the month and for the day and month', function () {
        Contact::factory()->for($this->org)->create(['name' => 'A', 'created_at' => '2026-03-31 23:30:00']);

        expect(($this->matches)(($this->createdAt)(SegmentOperator::MonthIs, ['month' => 4])))->toBe(['A'])
            ->and(($this->matches)(($this->createdAt)(SegmentOperator::DayAndMonthIs, ['month' => 4, 'day' => 1])))->toBe(['A'])
            ->and(($this->matches)(($this->createdAt)(SegmentOperator::MonthIs, ['month' => 3])))->toBe([]);

        ($this->inTimezone)('UTC');

        expect(($this->matches)(($this->createdAt)(SegmentOperator::MonthIs, ['month' => 3])))->toBe(['A']);
    });

    it('is understood by PostgreSQL for the usual timezones', function (string $timezone, string $localDate) {
        ($this->inTimezone)($timezone);
        Contact::factory()->for($this->org)->create(['name' => 'A', 'created_at' => '2026-03-15 23:10:00']);

        expect(($this->matches)(($this->createdAt)(SegmentOperator::On, ['value' => $localDate])))->toBe(['A']);
    })->with([
        'Paris' => ['Europe/Paris', '2026-03-16'],
        'Kolkata' => ['Asia/Kolkata', '2026-03-16'],
        'New York' => ['America/New_York', '2026-03-15'],
        'Kiritimati' => ['Pacific/Kiritimati', '2026-03-16'],
    ]);
});

describe('other dates', function () {
    it('leaves the dates of custom fields as they are but starts today at local midnight', function () {
        $renewal = CustomField::factory()->for($this->org)->create(['name' => 'Renewal', 'type' => 'date']);
        Contact::factory()->for($this->org)->withCustomFields([$renewal->key => '2026-03-16'])->create(['name' => 'A']);
        $condition = SegmentConditionData::make(SegmentConditionType::CustomField, $renewal->key, SegmentOperator::WithinLastDays, ['days' => 1]);

        expect(($this->matches)($condition))->toBe(['A']);

        ($this->inTimezone)('UTC');

        expect(($this->matches)($condition))->toBe([]);
    });

    it('does not move the activity windows, which compare instants', function (string $timezone) {
        ($this->inTimezone)($timezone);
        $contact = Contact::factory()->for($this->org)->create(['name' => 'A']);
        Activity::factory()->for($this->org)->for($contact)->create(['type' => ActivityType::Call, 'occurred_at' => now()->subHours(36)]);
        $condition = SegmentConditionData::make(SegmentConditionType::Activity, null, SegmentOperator::HasHadActivity, ['activity_types' => [ActivityType::Call->value], 'days' => 2]);

        expect(($this->matches)($condition))->toBe(['A']);
    })->with(['UTC', 'Pacific/Kiritimati']);
});

describe('the timezone of the organization', function () {
    it('is never put in SQL unless it is a known identifier', function () {
        DB::table('organizations')->where('id', $this->org->id)->update(['timezone' => "x' OR 1=1 --"]);

        expect(fn () => ($this->matches)(($this->createdAt)(SegmentOperator::On, ['value' => '2026-03-16'])))
            ->toThrow(InvalidArgumentException::class, 'Invalid organization timezone.');
    });

    it('is looked up once per catalog', function () {
        $builder = SegmentQueryBuilder::forOrganization($this->org->id);
        $condition = ($this->createdAt)(SegmentOperator::On, ['value' => '2026-03-16']);
        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'from "organizations"')) {
                $queries++;
            }
        });

        $builder->matching([new SegmentRuleData('r1', 'One', [$condition])])->get();
        $builder->matching([new SegmentRuleData('r2', 'Two', [$condition])])->get();

        expect($queries)->toBe(1);
    });
});
