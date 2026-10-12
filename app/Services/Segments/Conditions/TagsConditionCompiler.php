<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use Illuminate\Database\Eloquent\Builder;

class TagsConditionCompiler implements SegmentConditionCompiler
{
    use ComparesExpressions;

    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void
    {
        $tagIds = array_map('intval', $condition->values());
        $inTags = fn (Builder $tags): Builder => $tags->whereKey($tagIds);

        match ($condition->operator) {
            SegmentOperator::HasAnyOf => $query->whereHas('tags', $inTags),
            SegmentOperator::HasAllOf => $query->whereHas('tags', $inTags, '>=', count($tagIds)),
            SegmentOperator::HasNoneOf => $query->whereDoesntHave('tags', $inTags),
            SegmentOperator::HasOnly => $query
                ->whereHas('tags', $inTags, '>=', count($tagIds))
                ->whereDoesntHave('tags', fn (Builder $tags): Builder => $tags->whereKeyNot($tagIds)),
            SegmentOperator::HasNoTags => $query->whereDoesntHave('tags'),
            SegmentOperator::HasAnyTags => $query->whereHas('tags'),
            default => throw $this->unsupported($condition),
        };
    }
}
