<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Compiles conditions on custom field values stored in the `custom_field_values` JSONB column.
 *
 * Numbers and dates are only cast when the stored text looks valid, so malformed values never make the query fail.
 */
class CustomFieldConditionCompiler implements SegmentConditionCompiler
{
    use ComparesExpressions;

    public function apply(Builder $query, SegmentConditionData $condition, SegmentFieldKind $kind): void
    {
        $key = (string) $condition->field;

        if (! preg_match('/^[A-Za-z0-9_]+$/', $key)) {
            throw new InvalidArgumentException("Invalid custom field key [{$key}].");
        }

        $column = $query->getQuery()->getGrammar()->wrap($query->qualifyColumn('custom_field_values'));
        $json = "({$column}->'{$key}')";
        $text = "({$column}->>'{$key}')";
        $scalarBlank = "({$text} IS NULL OR {$text} = '')";

        match ($kind) {
            SegmentFieldKind::Number => $this->applyNumberOperator(
                $query,
                $condition,
                "(CASE WHEN {$text} ~ '^\s*-?[0-9]+(\.[0-9]+)?\s*$' THEN CAST({$text} AS numeric) END)",
                $scalarBlank,
            ),
            SegmentFieldKind::Date => $this->applyDateOperator(
                $query,
                $condition,
                "(CASE WHEN {$text} ~ '^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01])' THEN CAST(substring({$text} from 1 for 10) AS date) END)",
                $scalarBlank,
            ),
            SegmentFieldKind::Select => $this->applySelectOperator($query, $condition, $text, $scalarBlank),
            SegmentFieldKind::MultiSelect => $this->applyMultiSelectOperator($query, $condition, $json),
            default => $this->applyTextOperator($query, $condition, $text, $scalarBlank),
        };
    }

    private function applyMultiSelectOperator(Builder $query, SegmentConditionData $condition, string $json): void
    {
        $values = array_map('strval', $condition->values());
        $array = 'ARRAY['.implode(', ', array_fill(0, max(count($values), 1), '?')).']::text[]';
        $containsAny = "COALESCE(jsonb_exists_any({$json}, {$array}), false)";
        $containsAll = "COALESCE({$json} @> CAST(? AS jsonb), false)";

        match ($condition->operator) {
            SegmentOperator::ContainsAnyOf => $query->whereRaw($containsAny, $values),
            SegmentOperator::ContainsAllOf => $query->whereRaw($containsAll, [json_encode($values)]),
            SegmentOperator::ContainsNoneOf => $query->whereRaw("NOT {$containsAny}", $values),
            SegmentOperator::DoesNotContainAllOf => $query->whereRaw("NOT {$containsAll}", [json_encode($values)]),
            SegmentOperator::IsBlank, SegmentOperator::IsNotBlank => $this->applyBlankOperator(
                $query,
                $condition,
                "({$json} IS NULL OR {$json} IN ('null'::jsonb, '[]'::jsonb, '\"\"'::jsonb))",
            ),
            default => throw $this->unsupported($condition),
        };
    }
}
