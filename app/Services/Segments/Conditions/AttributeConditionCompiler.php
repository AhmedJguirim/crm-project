<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\ContactAttribute;
use App\Enums\SegmentFieldKind;
use Illuminate\Database\Eloquent\Builder;

class AttributeConditionCompiler implements SegmentConditionCompiler
{
    use ComparesExpressions;

    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void
    {
        $attribute = ContactAttribute::from((string) $condition->field);
        $column = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($attribute->value));

        match ($kind) {
            SegmentFieldKind::Date => $this->applyDateOperator($query, $condition, "CAST({$column} AS date)", "{$column} IS NULL"),
            SegmentFieldKind::Select => $this->applySelectOperator($query, $condition, $column, "{$column} IS NULL"),
            default => $this->applyTextOperator($query, $condition, $column, "({$column} IS NULL OR {$column} = '')"),
        };
    }
}
