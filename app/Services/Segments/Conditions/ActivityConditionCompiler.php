<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use Illuminate\Database\Eloquent\Builder;

class ActivityConditionCompiler implements SegmentConditionCompiler
{
    use ComparesExpressions;

    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void
    {
        $types = $condition->values('activity_types');
        $outcome = $condition->value('outcome');
        $days = filled($condition->value('days')) ? (int) $condition->value('days') : null;

        $matchingActivities = function (Builder $activities) use ($types, $outcome): Builder {
            return $activities
                ->when($types !== [], fn (Builder $query): Builder => $query->whereIn('type', $types))
                ->when(filled($outcome), fn (Builder $query): Builder => $query->where('outcome', $outcome));
        };

        $withinPeriod = fn (Builder $activities): Builder => $matchingActivities($activities)
            ->when($days !== null, fn (Builder $query): Builder => $query->where('occurred_at', '>=', now()->subDays($days)));

        match ($condition->operator) {
            SegmentOperator::HasHadActivity => $query->whereHas('activities', $withinPeriod),
            SegmentOperator::HasNotHadActivity => $query->whereDoesntHave('activities', $withinPeriod),
            SegmentOperator::LastActivityMoreThanDaysAgo => $query
                ->whereHas('activities', $matchingActivities)
                ->whereDoesntHave('activities', $withinPeriod),
            default => throw $this->unsupported($condition),
        };
    }
}
