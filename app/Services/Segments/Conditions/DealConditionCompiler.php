<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use Illuminate\Database\Eloquent\Builder;

class DealConditionCompiler implements SegmentConditionCompiler
{
    use ComparesExpressions;

    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void
    {
        $statuses = $condition->values('deal_statuses');
        $stages = $condition->values('deal_stages');
        $minValue = $condition->value('min_value');

        $matchingDeals = fn (Builder $deals): Builder => $deals
            ->when($statuses !== [], fn (Builder $query): Builder => $query->whereIn('status', $statuses))
            ->when($stages !== [], fn (Builder $query): Builder => $query->whereIn('stage', $stages))
            ->when(is_numeric($minValue), fn (Builder $query): Builder => $query->where('value', '>=', $minValue));

        match ($condition->operator) {
            SegmentOperator::HasDeal => $query->whereHas('deals', $matchingDeals),
            SegmentOperator::HasNoDeal => $query->whereDoesntHave('deals', $matchingDeals),
            default => throw $this->unsupported($condition),
        };
    }
}
