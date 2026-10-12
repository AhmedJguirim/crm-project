<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use Illuminate\Database\Eloquent\Builder;

class CompanyConditionCompiler implements SegmentConditionCompiler
{
    use ComparesExpressions;

    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void
    {
        $ids = array_map('intval', $condition->values());
        $inCompanies = fn (Builder $companies): Builder => $companies->whereKey($ids);

        match ($condition->operator) {
            SegmentOperator::BelongsToAnyOf => $query->whereHas('companies', $inCompanies),
            SegmentOperator::BelongsToNoneOf => $query->whereDoesntHave('companies', $inCompanies),
            SegmentOperator::CompanyTypeIsAnyOf => $query->whereHas(
                'companies',
                fn (Builder $companies): Builder => $companies->whereIn('company_type_id', $ids),
            ),
            SegmentOperator::HasNoCompany => $query->whereDoesntHave('companies'),
            SegmentOperator::HasAnyCompany => $query->whereHas('companies'),
            default => throw $this->unsupported($condition),
        };
    }
}
