<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Exceptions\UsedInSegmentsException;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Services\Segments\SegmentUsage;
use App\Services\Segments\SegmentUsageIndex;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

function usageIndexSegment(Organization $organization, array $conditions, string $name = 'Newsletter', bool $asDraft = false): Segment
{
    $rules = [(new SegmentRuleData('rule-1', 'Rule', $conditions))->toArray()];

    return Segment::factory()->for($organization)->create([
        'name' => $name,
        'rules' => $asDraft ? [] : $rules,
        'draft_rules' => $asDraft ? $rules : null,
    ]);
}

/** @param  array<string, mixed>  $value */
function usageIndexCondition(SegmentConditionType $type, ?string $field, ?SegmentOperator $operator, array $value = []): SegmentConditionData
{
    return SegmentConditionData::make($type, $field, $operator ?? SegmentOperator::HasAnyOf, $value);
}

/** @return array<int, string> */
function usageIndexQueries(): array
{
    return collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $query): bool => str_starts_with($query, 'select "id", "name", "rules", "draft_rules" from "segments"'))
        ->values()
        ->all();
}

describe('parity with the authoritative check', function () {
    it('finds the segments the database check finds, for every kind of reference', function (Closure $scenario) {
        [$record, $segment] = $scenario($this->org);

        $fromIndex = (new SegmentUsageIndex)->segmentNamesUsing($record);
        $fromDatabase = SegmentUsage::segmentsUsing($record)->pluck('name')->all();

        expect($fromIndex)->toBe(['Newsletter'])
            ->and($fromDatabase)->toBe(['Newsletter'])
            ->and((new SegmentUsageIndex)->isUsed($record))->toBeTrue();
    })->with([
        'custom field in the published rules' => [function (Organization $org): array {
            $field = CustomField::factory()->for($org)->create();

            return [$field, usageIndexSegment($org, [usageIndexCondition(SegmentConditionType::CustomField, $field->key, SegmentOperator::IsBlank)])];
        }],
        'tag in the draft rules only' => [function (Organization $org): array {
            $tag = Tag::factory()->for($org)->create();

            return [$tag, usageIndexSegment($org, [usageIndexCondition(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]])], asDraft: true)];
        }],
        'tag stored as a string' => [function (Organization $org): array {
            $tag = Tag::factory()->for($org)->create();

            return [$tag, usageIndexSegment($org, [usageIndexCondition(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [(string) $tag->id]])])];
        }],
        'company in belongs to none of' => [function (Organization $org): array {
            $company = Company::factory()->for($org)->create();

            return [$company, usageIndexSegment($org, [usageIndexCondition(SegmentConditionType::Company, null, SegmentOperator::BelongsToNoneOf, ['values' => [$company->id]])])];
        }],
        'company type in belongs to a company of type' => [function (Organization $org): array {
            $type = CompanyType::factory()->for($org)->create();

            return [$type, usageIndexSegment($org, [usageIndexCondition(SegmentConditionType::Company, null, SegmentOperator::CompanyTypeIsAnyOf, ['values' => [$type->id]])])];
        }],
    ]);

    it('finds nothing for records no segment references, like the database check', function () {
        $tag = Tag::factory()->for($this->org)->create();
        $field = CustomField::factory()->for($this->org)->create();
        usageIndexSegment($this->org, [usageIndexCondition(SegmentConditionType::CustomField, 'cf_other', SegmentOperator::IsBlank)]);

        $index = new SegmentUsageIndex;

        expect($index->isUsed($tag))->toBeFalse()
            ->and($index->isUsed($field))->toBeFalse()
            ->and(SegmentUsage::isUsed($tag))->toBeFalse()
            ->and(SegmentUsage::isUsed($field))->toBeFalse();
    });

    it('lists several segments alphabetically', function () {
        $tag = Tag::factory()->for($this->org)->create();
        $condition = usageIndexCondition(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]]);
        usageIndexSegment($this->org, [$condition], 'Zebra');
        usageIndexSegment($this->org, [$condition], 'Alpha');

        expect((new SegmentUsageIndex)->segmentNamesUsing($tag))->toBe(['Alpha', 'Zebra'])
            ->and(SegmentUsage::segmentsUsing($tag)->pluck('name')->all())->toBe(['Alpha', 'Zebra']);
    });

    it('does not confuse records of different kinds that share an ID', function () {
        $type = CompanyType::factory()->for($this->org)->create();
        $company = Company::factory()->for($this->org)->create(['id' => $type->id]);
        usageIndexSegment($this->org, [usageIndexCondition(SegmentConditionType::Company, null, SegmentOperator::CompanyTypeIsAnyOf, ['values' => [$type->id]])]);

        $index = new SegmentUsageIndex;

        expect($index->isUsed($type))->toBeTrue()
            ->and($index->isUsed($company))->toBeFalse()
            ->and(SegmentUsage::isUsed($company))->toBeFalse();
    });

    it('ignores the segments of other organizations', function () {
        $tag = Tag::factory()->for($this->org)->create();
        usageIndexSegment(Organization::factory()->create(), [usageIndexCondition(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]])]);

        expect((new SegmentUsageIndex)->isUsed($tag))->toBeFalse()
            ->and(SegmentUsage::isUsed($tag))->toBeFalse();
    });
});

describe('request scope', function () {
    it('is rebuilt after a segment changes in the same request', function () {
        $tag = Tag::factory()->for($this->org)->create();

        expect(app(SegmentUsageIndex::class)->isUsed($tag))->toBeFalse();

        usageIndexSegment($this->org, [usageIndexCondition(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]])]);

        expect(app(SegmentUsageIndex::class)->isUsed($tag))->toBeTrue();
    });
});

describe('display checks', function () {
    it('queries the segments once for a whole companies table', function () {
        $companies = Company::factory()->for($this->org)->count(30)->create();
        $used = $companies->take(5);
        $used->each(fn (Company $company) => usageIndexSegment($this->org, [usageIndexCondition(SegmentConditionType::Company, null, SegmentOperator::BelongsToAnyOf, ['values' => [$company->id]])], "Segment {$company->id}"));

        DB::enableQueryLog();
        $page = Livewire::test(ListCompanies::class);

        expect(usageIndexQueries())->toHaveCount(1);

        foreach ($used as $company) {
            $page->assertActionDisabled(TestAction::make('delete')->table($company));
        }

        foreach ($companies->skip(5)->take(3) as $company) {
            $page->assertActionEnabled(TestAction::make('delete')->table($company));
        }
    });

    it('queries the segments once on the custom field edit page', function () {
        $field = CustomField::factory()->for($this->org)->create();
        usageIndexSegment($this->org, [usageIndexCondition(SegmentConditionType::CustomField, $field->key, SegmentOperator::IsBlank)]);

        DB::enableQueryLog();
        $page = Livewire::test(EditCustomField::class, ['record' => $field->getRouteKey()]);

        expect(usageIndexQueries())->toHaveCount(1);

        $page->assertFormFieldIsDisabled('type')
            ->assertSee("The type can't change after the field is created.");
    });
});

describe('enforcement', function () {
    it('is never served from the index', function () {
        $tag = Tag::factory()->for($this->org)->create();
        $index = app(SegmentUsageIndex::class);

        expect($index->isUsed($tag))->toBeFalse();

        Segment::withoutEvents(fn (): Segment => usageIndexSegment($this->org, [usageIndexCondition(SegmentConditionType::Tags, null, SegmentOperator::HasAnyOf, ['values' => [$tag->id]])]));

        expect($index->isUsed($tag))->toBeFalse()
            ->and(fn () => $tag->delete())->toThrow(UsedInSegmentsException::class, 'used in the conditions of');
        expect($tag->fresh()->trashed())->toBeFalse();
    });
});
