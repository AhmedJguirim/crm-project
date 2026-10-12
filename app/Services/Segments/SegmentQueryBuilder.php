<?php

namespace App\Services\Segments;

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\SegmentConditionType;
use App\Models\Contact;
use App\Services\Segments\Conditions\ActivityConditionCompiler;
use App\Services\Segments\Conditions\AttributeConditionCompiler;
use App\Services\Segments\Conditions\CompanyConditionCompiler;
use App\Services\Segments\Conditions\CustomFieldConditionCompiler;
use App\Services\Segments\Conditions\DealConditionCompiler;
use App\Services\Segments\Conditions\SegmentConditionCompiler;
use App\Services\Segments\Conditions\TagsConditionCompiler;
use Illuminate\Database\Eloquent\Builder;

/**
 * Compiles segment rules into a contacts query: a contact matches when it meets ALL conditions of ANY rule.
 *
 * Rules with an incomplete condition are skipped. The query is always scoped to the catalog's organization
 * explicitly, as it also runs in queued jobs where no Filament tenant is set.
 */
class SegmentQueryBuilder
{
    public function __construct(private readonly SegmentFieldCatalog $catalog) {}

    public static function forOrganization(int $organizationId): self
    {
        return new self(SegmentFieldCatalog::forOrganization($organizationId));
    }

    public function catalog(): SegmentFieldCatalog
    {
        return $this->catalog;
    }

    /** @return Builder<Contact> */
    public function contacts(): Builder
    {
        return Contact::query()
            ->withoutGlobalScope('organization')
            ->where('contacts.organization_id', $this->catalog->organizationId);
    }

    /**
     * @param  iterable<SegmentRuleData>  $rules
     * @return Builder<Contact>
     */
    public function matching(iterable $rules): Builder
    {
        return $this->applyRules($this->contacts(), $rules);
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  iterable<SegmentRuleData>  $rules
     * @return Builder<Contact>
     */
    public function applyRules(Builder $query, iterable $rules): Builder
    {
        $completeRules = collect($rules)->filter(fn (SegmentRuleData $rule): bool => $this->catalog->isRuleComplete($rule));

        if ($completeRules->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($completeRules): void {
            foreach ($completeRules as $rule) {
                $query->orWhere(fn (Builder $ruleQuery): Builder => $this->applyConditions($ruleQuery, $rule->conditions));
            }
        });
    }

    /**
     * @param  Builder<Contact>  $query
     * @param  array<int, SegmentConditionData>  $conditions
     * @return Builder<Contact>
     */
    public function applyConditions(Builder $query, array $conditions): Builder
    {
        foreach ($conditions as $condition) {
            if (! $this->catalog->isConditionComplete($condition)) {
                return $query->whereRaw('1 = 0');
            }

            $query->where(fn (Builder $conditionQuery) => $this->compilerFor($condition->type)
                ->apply($conditionQuery, $condition, $this->catalog->fieldKind($condition)));
        }

        return $query;
    }

    /** @param  iterable<SegmentRuleData>  $rules */
    public function count(iterable $rules): int
    {
        return $this->matching($rules)->count();
    }

    /** @param  array<int, SegmentConditionData>  $conditions */
    public function countConditions(array $conditions): int
    {
        if ($conditions === []) {
            return 0;
        }

        return $this->applyConditions($this->contacts(), $conditions)->count();
    }

    private function compilerFor(SegmentConditionType $type): SegmentConditionCompiler
    {
        return match ($type) {
            SegmentConditionType::Attribute => new AttributeConditionCompiler($this->catalog->timezone()),
            SegmentConditionType::CustomField => new CustomFieldConditionCompiler($this->catalog->timezone()),
            SegmentConditionType::Tags => new TagsConditionCompiler,
            SegmentConditionType::Company => new CompanyConditionCompiler,
            SegmentConditionType::Activity => new ActivityConditionCompiler,
            SegmentConditionType::Deal => new DealConditionCompiler,
        };
    }
}
