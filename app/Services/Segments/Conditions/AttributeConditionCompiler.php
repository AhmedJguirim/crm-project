<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\ContactAttribute;
use App\Enums\SegmentFieldKind;
use Illuminate\Database\Eloquent\Builder;

class AttributeConditionCompiler implements SegmentConditionCompiler
{
    use ComparesExpressions;

    public function __construct(private readonly string $timezone) {}

    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void
    {
        $attribute = ContactAttribute::from((string) $condition->field);
        $column = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($attribute->value));

        match ($kind) {
            SegmentFieldKind::Date => $this->applyDateOperator($query, $condition, $this->localDate($column), "{$column} IS NULL"),
            SegmentFieldKind::Select => $this->applySelectOperator($query, $condition, $column, "{$column} IS NULL"),
            default => $this->applyTextOperator($query, $condition, $column, "({$column} IS NULL OR {$column} = '')"),
        };
    }

    /**
     * The date of a timestamp column in the organization's timezone. The column holds UTC. The timezone is inlined
     * because the expression is reused by several `whereRaw` calls with their own bindings; the catalog has already
     * checked it is an IANA identifier.
     */
    private function localDate(string $column): string
    {
        return "CAST(({$column} AT TIME ZONE 'UTC') AT TIME ZONE '{$this->timezone}' AS date)";
    }
}
