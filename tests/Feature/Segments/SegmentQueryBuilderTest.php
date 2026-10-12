<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\ContactAttribute;
use App\Enums\ContactStatus;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\LeadSource;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Models\Activity;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use App\Services\Segments\SegmentQueryBuilder;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;

function segmentCondition(SegmentConditionType $type, ?string $field, SegmentOperator $operator, array $value = []): SegmentConditionData
{
    return SegmentConditionData::make($type, $field, $operator, $value);
}

function segmentRule(SegmentConditionData ...$conditions): SegmentRuleData
{
    return new SegmentRuleData((string) str()->ulid(), 'Rule', $conditions);
}

/**
 * @param  array<int, SegmentRuleData>|SegmentConditionData  $rules
 * @return array<int, string>
 */
function segmentMatches(Organization $organization, array|SegmentConditionData $rules): array
{
    $rules = $rules instanceof SegmentConditionData ? [segmentRule($rules)] : $rules;

    return SegmentQueryBuilder::forOrganization($organization->id)
        ->matching($rules)
        ->orderBy('name')
        ->pluck('name')
        ->all();
}

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 6, 15)->setTime(12, 0));

    $this->org = Organization::factory()->create();
    app(TenantContext::class)->set($this->org->id);
});

describe('contact attributes', function () {
    beforeEach(function () {
        Contact::factory()->for($this->org)->create([
            'name' => 'Alice', 'email' => 'alice@acme.com', 'phone' => null,
            'status' => ContactStatus::Lead, 'lead_source' => LeadSource::Referral, 'created_at' => now()->subDays(10),
        ]);
        Contact::factory()->for($this->org)->create([
            'name' => 'Bob', 'email' => 'bob@globex.com', 'phone' => '555-0100',
            'status' => ContactStatus::ActiveClient, 'created_at' => now()->subDays(40),
        ]);
        Contact::factory()->for($this->org)->create([
            'name' => 'Carol', 'email' => 'carol@ACME.com', 'phone' => '',
            'status' => null, 'created_at' => now(),
        ]);
    });

    it('matches text operators case-insensitively', function (SegmentOperator $operator, string $value, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, $operator, ['value' => $value])))
            ->toBe($expected);
    })->with([
        'is' => [SegmentOperator::Is, 'alice', ['Alice']],
        'is not' => [SegmentOperator::IsNot, 'ALICE', ['Bob', 'Carol']],
        'contains' => [SegmentOperator::Contains, 'O', ['Bob', 'Carol']],
        'does not contain' => [SegmentOperator::DoesNotContain, 'o', ['Alice']],
        'starts with' => [SegmentOperator::StartsWith, 'ca', ['Carol']],
        'ends with' => [SegmentOperator::EndsWith, 'B', ['Bob']],
        'contains escapes wildcards' => [SegmentOperator::Contains, '%', []],
    ]);

    it('matches blank operators', function () {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Phone->value, SegmentOperator::IsBlank)))
            ->toBe(['Alice', 'Carol'])
            ->and(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Phone->value, SegmentOperator::IsNotBlank)))
            ->toBe(['Bob']);
    });

    it('matches email domains', function () {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Email->value, SegmentOperator::IsFromDomain, ['value' => 'acme.com'])))
            ->toBe(['Alice', 'Carol'])
            ->and(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Email->value, SegmentOperator::IsNotFromDomain, ['value' => '@acme.com'])))
            ->toBe(['Bob']);
    });

    it('matches enum select attributes', function (SegmentOperator $operator, array $value, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Status->value, $operator, $value)))
            ->toBe($expected);
    })->with([
        'is' => [SegmentOperator::Is, ['value' => ContactStatus::Lead->value], ['Alice']],
        'is not' => [SegmentOperator::IsNot, ['value' => ContactStatus::Lead->value], ['Bob', 'Carol']],
        'is any of' => [SegmentOperator::IsAnyOf, ['values' => [ContactStatus::Lead->value, ContactStatus::ActiveClient->value]], ['Alice', 'Bob']],
        'is none of' => [SegmentOperator::IsNoneOf, ['values' => [ContactStatus::Lead->value]], ['Bob', 'Carol']],
        'is blank' => [SegmentOperator::IsBlank, [], ['Carol']],
    ]);

    it('matches the created date', function (SegmentOperator $operator, array $value, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, $operator, $value)))
            ->toBe($expected);
    })->with([
        'within last days' => [SegmentOperator::WithinLastDays, ['days' => 30], ['Alice', 'Carol']],
        'more than days ago' => [SegmentOperator::MoreThanDaysAgo, ['days' => 30], ['Bob']],
        'before' => [SegmentOperator::Before, ['value' => '2026-06-01'], ['Bob']],
        'on' => [SegmentOperator::On, ['value' => '2026-06-05'], ['Alice']],
        'on or after' => [SegmentOperator::OnOrAfter, ['value' => '2026-06-05'], ['Alice', 'Carol']],
    ]);
});

describe('custom fields', function () {
    beforeEach(function () {
        $this->industry = CustomField::factory()->for($this->org)->text()->create(['name' => 'Industry']);
        $this->score = CustomField::factory()->for($this->org)->create(['name' => 'Score', 'type' => 'number']);
        $this->birthday = CustomField::factory()->for($this->org)->create(['name' => 'Birthday', 'type' => 'date']);
        $this->plan = CustomField::factory()->for($this->org)->select()->create(['name' => 'Plan']);
        $this->interests = CustomField::factory()->for($this->org)->multiselect()->create(['name' => 'Interests']);

        Contact::factory()->for($this->org)->withCustomFields([
            $this->industry->key => 'Technology',
            $this->score->key => 10,
            $this->birthday->key => '1990-03-14',
            $this->plan->key => 'opt1',
            $this->interests->key => ['tag1', 'tag2'],
        ])->create(['name' => 'A']);

        Contact::factory()->for($this->org)->withCustomFields([
            $this->industry->key => '',
            $this->score->key => '25.5',
            $this->birthday->key => '1985-07-01',
            $this->plan->key => 'opt2',
            $this->interests->key => ['tag3'],
        ])->create(['name' => 'B']);

        Contact::factory()->for($this->org)->withCustomFields([
            $this->score->key => 'abc',
            $this->birthday->key => 'not-a-date',
            $this->interests->key => [],
        ])->create(['name' => 'C']);

        Contact::factory()->for($this->org)->create(['name' => 'D']);
    });

    it('matches text custom fields', function () {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $this->industry->key, SegmentOperator::Contains, ['value' => 'tech'])))
            ->toBe(['A'])
            ->and(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $this->industry->key, SegmentOperator::IsBlank)))
            ->toBe(['B', 'C', 'D']);
    });

    it('matches number custom fields and ignores malformed values', function (SegmentOperator $operator, array $value, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $this->score->key, $operator, $value)))
            ->toBe($expected);
    })->with([
        'greater than' => [SegmentOperator::GreaterThan, ['value' => '15'], ['B']],
        'less than or equal' => [SegmentOperator::LessThanOrEqualTo, ['value' => 10], ['A']],
        'equal to' => [SegmentOperator::EqualTo, ['value' => '25.5'], ['B']],
        'not equal to includes blank and malformed values' => [SegmentOperator::NotEqualTo, ['value' => '10'], ['B', 'C', 'D']],
        'greater than 0' => [SegmentOperator::GreaterThan, ['value' => '0'], ['A', 'B']],
        'less than 100' => [SegmentOperator::LessThan, ['value' => '100'], ['A', 'B']],
        'greater than or equal to' => [SegmentOperator::GreaterThanOrEqualTo, ['value' => '10'], ['A', 'B']],
        'less than or equal to 25.5' => [SegmentOperator::LessThanOrEqualTo, ['value' => '25.5'], ['A', 'B']],
        'is blank' => [SegmentOperator::IsBlank, [], ['D']],
    ]);

    it('covers every contact with a condition and its negative', function (string $field, SegmentOperator $positive, SegmentOperator $negative, array $value) {
        Contact::where('name', 'A')->sole()->update(['email' => 'a@example.com']);

        $key = match ($field) {
            'industry' => $this->industry->key,
            'plan' => $this->plan->key,
            'interests' => $this->interests->key,
            'score' => $this->score->key,
            default => ContactAttribute::Email->value,
        };
        $type = $field === 'email' ? SegmentConditionType::Attribute : SegmentConditionType::CustomField;

        $matching = segmentMatches($this->org, segmentCondition($type, $key, $positive, $value));
        $notMatching = segmentMatches($this->org, segmentCondition($type, $key, $negative, $value));

        expect($matching)->not->toBeEmpty()
            ->and(array_intersect($matching, $notMatching))->toBe([])
            ->and([...$matching, ...$notMatching])->toEqualCanonicalizing(['A', 'B', 'C', 'D']);
    })->with([
        'text is' => ['industry', SegmentOperator::Is, SegmentOperator::IsNot, ['value' => 'Technology']],
        'text contains' => ['industry', SegmentOperator::Contains, SegmentOperator::DoesNotContain, ['value' => 'tech']],
        'email domain' => ['email', SegmentOperator::IsFromDomain, SegmentOperator::IsNotFromDomain, ['value' => 'example.com']],
        'select is' => ['plan', SegmentOperator::Is, SegmentOperator::IsNot, ['value' => 'opt1']],
        'select any of' => ['plan', SegmentOperator::IsAnyOf, SegmentOperator::IsNoneOf, ['values' => ['opt1']]],
        'multi-select any of' => ['interests', SegmentOperator::ContainsAnyOf, SegmentOperator::ContainsNoneOf, ['values' => ['tag1']]],
        'multi-select all of' => ['interests', SegmentOperator::ContainsAllOf, SegmentOperator::DoesNotContainAllOf, ['values' => ['tag1', 'tag2']]],
        'number equal to' => ['score', SegmentOperator::EqualTo, SegmentOperator::NotEqualTo, ['value' => '10']],
    ]);

    it('matches date custom fields and ignores malformed values', function (SegmentOperator $operator, array $value, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $this->birthday->key, $operator, $value)))
            ->toBe($expected);
    })->with([
        'before' => [SegmentOperator::Before, ['value' => '1989-01-01'], ['B']],
        'between (reversed bounds)' => [SegmentOperator::Between, ['value' => '1991-01-01', 'value_to' => '1980-01-01'], ['A', 'B']],
        'on' => [SegmentOperator::On, ['value' => '1990-03-14'], ['A']],
        'month is' => [SegmentOperator::MonthIs, ['month' => 3], ['A']],
        'day and month is' => [SegmentOperator::DayAndMonthIs, ['month' => 7, 'day' => 1], ['B']],
    ]);

    it('matches select custom fields', function (SegmentOperator $operator, array $value, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $this->plan->key, $operator, $value)))
            ->toBe($expected);
    })->with([
        'is' => [SegmentOperator::Is, ['value' => 'opt1'], ['A']],
        'is not' => [SegmentOperator::IsNot, ['value' => 'opt1'], ['B', 'C', 'D']],
        'is any of' => [SegmentOperator::IsAnyOf, ['values' => ['opt1', 'opt2']], ['A', 'B']],
        'is none of' => [SegmentOperator::IsNoneOf, ['values' => ['opt1']], ['B', 'C', 'D']],
        'is blank' => [SegmentOperator::IsBlank, [], ['C', 'D']],
    ]);

    it('matches multi-select custom fields', function (SegmentOperator $operator, array $value, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $this->interests->key, $operator, $value)))
            ->toBe($expected);
    })->with([
        'contains any of' => [SegmentOperator::ContainsAnyOf, ['values' => ['tag2', 'tag3']], ['A', 'B']],
        'contains all of' => [SegmentOperator::ContainsAllOf, ['values' => ['tag1', 'tag2']], ['A']],
        'contains none of' => [SegmentOperator::ContainsNoneOf, ['values' => ['tag1']], ['B', 'C', 'D']],
        'does not contain all of' => [SegmentOperator::DoesNotContainAllOf, ['values' => ['tag1', 'tag3']], ['A', 'B', 'C', 'D']],
        'is blank' => [SegmentOperator::IsBlank, [], ['C', 'D']],
        'is not blank' => [SegmentOperator::IsNotBlank, [], ['A', 'B']],
    ]);

    it('keeps matching on soft-deleted custom fields', function () {
        $this->plan->delete();

        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $this->plan->key, SegmentOperator::Is, ['value' => 'opt2'])))
            ->toBe(['B']);
    });

    it('matches nothing for unknown custom fields', function () {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, 'cf_doesnotexist', SegmentOperator::IsNotBlank)))
            ->toBe([]);
    });
});

describe('tags', function () {
    beforeEach(function () {
        $this->vip = Tag::factory()->for($this->org)->create(['name' => 'VIP']);
        $this->lead = Tag::factory()->for($this->org)->create(['name' => 'Lead']);

        Contact::factory()->for($this->org)->withTags([$this->vip->id])->create(['name' => 'A']);
        Contact::factory()->for($this->org)->withTags([$this->vip->id, $this->lead->id])->create(['name' => 'B']);
        Contact::factory()->for($this->org)->create(['name' => 'C']);
        Contact::factory()->for($this->org)->withTags([$this->lead->id])->create(['name' => 'D']);
    });

    it('matches tag operators', function (SegmentOperator $operator, Closure $values, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Tags, null, $operator, ['values' => $values->call($this)])))
            ->toBe($expected);
    })->with([
        'has any of' => [SegmentOperator::HasAnyOf, fn () => [$this->vip->id], ['A', 'B']],
        'has all of' => [SegmentOperator::HasAllOf, fn () => [$this->vip->id, $this->lead->id], ['B']],
        'has none of' => [SegmentOperator::HasNoneOf, fn () => [$this->vip->id], ['C', 'D']],
        'has only one' => [SegmentOperator::HasOnly, fn () => [$this->vip->id], ['A']],
        'has only two' => [SegmentOperator::HasOnly, fn () => [$this->vip->id, $this->lead->id], ['B']],
        'has no tags' => [SegmentOperator::HasNoTags, fn () => [], ['C']],
        'has any tags' => [SegmentOperator::HasAnyTags, fn () => [], ['A', 'B', 'D']],
    ]);
});

describe('companies', function () {
    beforeEach(function () {
        $this->agency = CompanyType::factory()->for($this->org)->create();
        $this->vendor = CompanyType::factory()->for($this->org)->create();
        $this->acme = Company::factory()->for($this->org)->create(['company_type_id' => $this->agency->id]);
        $this->globex = Company::factory()->for($this->org)->create(['company_type_id' => $this->vendor->id]);

        Contact::factory()->for($this->org)->create(['name' => 'A'])->companies()->attach($this->acme);
        Contact::factory()->for($this->org)->create(['name' => 'B'])->companies()->attach([$this->acme->id, $this->globex->id]);
        Contact::factory()->for($this->org)->create(['name' => 'C']);
    });

    it('matches company operators', function (SegmentOperator $operator, Closure $values, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Company, null, $operator, ['values' => $values->call($this)])))
            ->toBe($expected);
    })->with([
        'belongs to any of' => [SegmentOperator::BelongsToAnyOf, fn () => [$this->globex->id], ['B']],
        'belongs to none of' => [SegmentOperator::BelongsToNoneOf, fn () => [$this->globex->id], ['A', 'C']],
        'company type is any of' => [SegmentOperator::CompanyTypeIsAnyOf, fn () => [$this->agency->id], ['A', 'B']],
        'has no company' => [SegmentOperator::HasNoCompany, fn () => [], ['C']],
        'has any company' => [SegmentOperator::HasAnyCompany, fn () => [], ['A', 'B']],
    ]);
});

describe('activities', function () {
    beforeEach(function () {
        $alice = Contact::factory()->for($this->org)->create(['name' => 'A']);
        $bob = Contact::factory()->for($this->org)->create(['name' => 'B']);
        Contact::factory()->for($this->org)->create(['name' => 'C']);

        Activity::factory()->for($this->org)->for($alice)->create([
            'type' => ActivityType::Call, 'outcome' => ActivityOutcome::Positive, 'occurred_at' => now()->subDays(5),
        ]);
        Activity::factory()->for($this->org)->for($bob)->create([
            'type' => ActivityType::Email, 'outcome' => null, 'occurred_at' => now()->subDays(60),
        ]);
    });

    it('matches activity operators', function (SegmentOperator $operator, array $value, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Activity, null, $operator, $value)))
            ->toBe($expected);
    })->with([
        'has had a call recently' => [SegmentOperator::HasHadActivity, ['activity_types' => [ActivityType::Call->value], 'days' => 30], ['A']],
        'has had any activity ever' => [SegmentOperator::HasHadActivity, [], ['A', 'B']],
        'has had a positive activity' => [SegmentOperator::HasHadActivity, ['outcome' => ActivityOutcome::Positive->value], ['A']],
        'has not had an activity recently' => [SegmentOperator::HasNotHadActivity, ['days' => 30], ['B', 'C']],
        'last activity long ago' => [SegmentOperator::LastActivityMoreThanDaysAgo, ['days' => 30], ['B']],
    ]);
});

describe('deals', function () {
    beforeEach(function () {
        $alice = Contact::factory()->for($this->org)->create(['name' => 'A']);
        $bob = Contact::factory()->for($this->org)->create(['name' => 'B']);
        Contact::factory()->for($this->org)->create(['name' => 'C']);

        Deal::factory()->for($this->org)->for($alice)->create(['status' => DealStatus::Open, 'stage' => DealStage::Lead, 'value' => 1000]);
        Deal::factory()->for($this->org)->for($bob)->create(['status' => DealStatus::Won, 'stage' => DealStage::Won, 'value' => 5000]);
    });

    it('matches deal operators', function (SegmentOperator $operator, array $value, array $expected) {
        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Deal, null, $operator, $value)))
            ->toBe($expected);
    })->with([
        'has an open deal' => [SegmentOperator::HasDeal, ['deal_statuses' => [DealStatus::Open->value]], ['A']],
        'has a deal in stage' => [SegmentOperator::HasDeal, ['deal_stages' => [DealStage::Won->value]], ['B']],
        'has a big deal' => [SegmentOperator::HasDeal, ['min_value' => 2000], ['B']],
        'has no deal' => [SegmentOperator::HasNoDeal, [], ['C']],
        'has no won deal' => [SegmentOperator::HasNoDeal, ['deal_statuses' => [DealStatus::Won->value]], ['A', 'C']],
    ]);
});

describe('rule logic', function () {
    beforeEach(function () {
        Contact::factory()->for($this->org)->create(['name' => 'Alice', 'email' => 'alice@acme.com', 'status' => ContactStatus::Lead]);
        Contact::factory()->for($this->org)->create(['name' => 'Bob', 'email' => 'bob@acme.com', 'status' => ContactStatus::Partner]);
        Contact::factory()->for($this->org)->create(['name' => 'Carol', 'email' => 'carol@globex.com', 'status' => ContactStatus::Partner]);
    });

    it('requires all conditions of a rule', function () {
        $rule = segmentRule(
            segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Email->value, SegmentOperator::IsFromDomain, ['value' => 'acme.com']),
            segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Partner->value]),
        );

        expect(segmentMatches($this->org, [$rule]))->toBe(['Bob']);
    });

    it('matches any rule', function () {
        $rules = [
            segmentRule(segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is, ['value' => 'Alice'])),
            segmentRule(segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is, ['value' => 'Carol'])),
        ];

        expect(segmentMatches($this->org, $rules))->toBe(['Alice', 'Carol']);
    });

    it('skips incomplete rules', function () {
        $rules = [
            segmentRule(segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is, ['value' => 'Alice'])),
            segmentRule(segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is, ['value' => ''])),
            segmentRule(),
        ];

        expect(segmentMatches($this->org, $rules))->toBe(['Alice']);
    });

    it('matches nothing without rules', function () {
        expect(segmentMatches($this->org, []))->toBe([]);
    });

    it('only matches contacts of the organization even when another tenant is active', function () {
        $otherOrganization = Organization::factory()->create();
        Contact::factory()->for($otherOrganization)->create(['name' => 'Alien', 'email' => 'alien@acme.com']);
        $this->actingAs(User::factory()->create());
        Filament::setTenant($otherOrganization);

        $condition = segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Email->value, SegmentOperator::IsFromDomain, ['value' => 'acme.com']);

        expect(segmentMatches($this->org, $condition))->toBe(['Alice', 'Bob']);
    });

    it('excludes soft-deleted contacts', function () {
        Contact::query()->where('name', 'Bob')->first()->delete();

        $condition = segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Email->value, SegmentOperator::IsFromDomain, ['value' => 'acme.com']);

        expect(segmentMatches($this->org, $condition))->toBe(['Alice']);
    });
});

describe('dates on both sources', function () {
    beforeEach(function () {
        $this->birthday = CustomField::factory()->for($this->org)->create(['name' => 'Birthday', 'type' => 'date']);

        foreach (['A' => '2026-06-10 23:30:00', 'B' => '2026-05-01 08:00:00', 'C' => '2026-06-15 09:00:00'] as $name => $date) {
            Contact::factory()->for($this->org)
                ->withCustomFields([$this->birthday->key => substr($date, 0, 10)])
                ->create(['name' => $name, 'created_at' => $date]);
        }
    });

    it('applies every date operator the same way to the created date and to date custom fields', function (SegmentOperator $operator, array $value, array $expected) {
        $onAttribute = segmentCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, $operator, $value);
        $onCustomField = segmentCondition(SegmentConditionType::CustomField, $this->birthday->key, $operator, $value);

        expect(segmentMatches($this->org, $onAttribute))->toBe($expected)
            ->and(segmentMatches($this->org, $onCustomField))->toBe($expected);
    })->with([
        'before' => [SegmentOperator::Before, ['value' => '2026-06-10'], ['B']],
        'after' => [SegmentOperator::After, ['value' => '2026-06-10'], ['C']],
        'on (ignores the time of day)' => [SegmentOperator::On, ['value' => '2026-06-10'], ['A']],
        'on or before' => [SegmentOperator::OnOrBefore, ['value' => '2026-06-10'], ['A', 'B']],
        'on or after' => [SegmentOperator::OnOrAfter, ['value' => '2026-06-10'], ['A', 'C']],
        'between (inclusive)' => [SegmentOperator::Between, ['value' => '2026-05-01', 'value_to' => '2026-06-10'], ['A', 'B']],
        'within the last days (includes today)' => [SegmentOperator::WithinLastDays, ['days' => 7], ['A', 'C']],
        'within the last days (boundary day included)' => [SegmentOperator::WithinLastDays, ['days' => 5], ['A', 'C']],
        'more than days ago' => [SegmentOperator::MoreThanDaysAgo, ['days' => 30], ['B']],
        'more than days ago (boundary day excluded)' => [SegmentOperator::MoreThanDaysAgo, ['days' => 5], ['B']],
        'month is' => [SegmentOperator::MonthIs, ['month' => 6], ['A', 'C']],
        'day and month is' => [SegmentOperator::DayAndMonthIs, ['month' => 5, 'day' => 1], ['B']],
    ]);

    it('treats missing date custom field values as blank', function () {
        Contact::factory()->for($this->org)->create(['name' => 'D']);

        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $this->birthday->key, SegmentOperator::IsBlank)))->toBe(['D'])
            ->and(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $this->birthday->key, SegmentOperator::IsNotBlank)))->toBe(['A', 'B', 'C']);
    });
});

describe('more custom field types', function () {
    it('compares numbers with every operator', function (SegmentOperator $operator, string $value, array $expected) {
        $score = CustomField::factory()->for($this->org)->create(['type' => 'number']);

        foreach (['Low' => 5, 'Mid' => '10', 'High' => -2.5] as $name => $number) {
            Contact::factory()->for($this->org)->withCustomFields([$score->key => $number])->create(['name' => $name]);
        }

        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $score->key, $operator, ['value' => $value])))
            ->toBe($expected);
    })->with([
        'less than' => [SegmentOperator::LessThan, '5', ['High']],
        'greater than or equal to' => [SegmentOperator::GreaterThanOrEqualTo, '5', ['Low', 'Mid']],
        'greater than (negative)' => [SegmentOperator::GreaterThan, '-3', ['High', 'Low', 'Mid']],
        'equal to (decimal input)' => [SegmentOperator::EqualTo, '10.0', ['Mid']],
    ]);

    it('applies text operators to email, url, phone and text area custom fields', function (string $type, SegmentOperator $operator, string $value, array $expected) {
        $field = CustomField::factory()->for($this->org)->create(['type' => $type]);
        $values = [
            'email' => ['A' => 'ann@acme.com', 'B' => 'bob@globex.com'],
            'url' => ['A' => 'https://acme.com/pricing', 'B' => 'https://globex.com'],
            'phone' => ['A' => '+216 555 0100', 'B' => '+33 555 0199'],
            'textarea' => ['A' => "Met at the\nconference", 'B' => 'Cold call'],
        ][$type];

        foreach ($values as $name => $fieldValue) {
            Contact::factory()->for($this->org)->withCustomFields([$field->key => $fieldValue])->create(['name' => $name]);
        }

        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::CustomField, $field->key, $operator, ['value' => $value])))
            ->toBe($expected);
    })->with([
        'email domain' => ['email', SegmentOperator::IsFromDomain, 'ACME.com', ['A']],
        'email not from domain' => ['email', SegmentOperator::IsNotFromDomain, 'acme.com', ['B']],
        'url contains' => ['url', SegmentOperator::Contains, 'pricing', ['A']],
        'phone starts with' => ['phone', SegmentOperator::StartsWith, '+216', ['A']],
        'text area contains across lines' => ['textarea', SegmentOperator::Contains, 'the conference', []],
        'text area ends with' => ['textarea', SegmentOperator::EndsWith, 'conference', ['A']],
    ]);
});

describe('text matching details', function () {
    it('matches LIKE wildcards and backslashes literally', function (string $value, array $expected) {
        foreach (['a_b', 'axb', '50%', '50x', 'back\\slash'] as $name) {
            Contact::factory()->for($this->org)->create(['name' => $name]);
        }

        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Contains, ['value' => $value])))
            ->toBe($expected);
    })->with([
        'underscore' => ['_', ['a_b']],
        'percent' => ['%', ['50%']],
        'backslash' => ['\\', ['back\\slash']],
    ]);

    it('matches the lead source and non-blank select attributes', function () {
        Contact::factory()->for($this->org)->create(['name' => 'Ref', 'lead_source' => LeadSource::Referral]);
        Contact::factory()->for($this->org)->create(['name' => 'Web', 'lead_source' => LeadSource::Website]);
        Contact::factory()->for($this->org)->create(['name' => 'None', 'lead_source' => null]);

        expect(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::LeadSource->value, SegmentOperator::Is, ['value' => LeadSource::Website->value])))->toBe(['Web'])
            ->and(segmentMatches($this->org, segmentCondition(SegmentConditionType::Attribute, ContactAttribute::LeadSource->value, SegmentOperator::IsNotBlank)))->toBe(['Ref', 'Web']);
    });
});

describe('incomplete rules', function () {
    it('skips a whole rule when one of its conditions is incomplete, keeping the other rules', function () {
        Contact::factory()->for($this->org)->create(['name' => 'Alice', 'status' => ContactStatus::Lead]);
        Contact::factory()->for($this->org)->create(['name' => 'Bob', 'status' => ContactStatus::Partner]);

        $rules = [
            segmentRule(
                segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Partner->value]),
                segmentCondition(SegmentConditionType::CustomField, 'cf_deleted', SegmentOperator::IsBlank),
            ),
            segmentRule(segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Status->value, SegmentOperator::Is, ['value' => ContactStatus::Lead->value])),
        ];

        expect(segmentMatches($this->org, $rules))->toBe(['Alice']);
    });

    it('counts nothing for an incomplete list of conditions', function () {
        Contact::factory()->for($this->org)->create();
        $builder = SegmentQueryBuilder::forOrganization($this->org->id);

        expect($builder->countConditions([]))->toBe(0)
            ->and($builder->countConditions([segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is)]))->toBe(0)
            ->and($builder->countConditions([segmentCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::IsNotBlank)]))->toBe(1);
    });
});
