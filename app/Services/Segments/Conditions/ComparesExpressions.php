<?php

namespace App\Services\Segments\Conditions;

use App\Data\Segments\SegmentConditionData;
use App\Enums\SegmentOperator;
use App\Services\Segments\SegmentFieldCatalog;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Operator → SQL mapping for conditions comparing a single SQL expression (a column or a JSON value).
 */
trait ComparesExpressions
{
    protected function applyTextOperator(Builder $query, SegmentConditionData $condition, string $expression, string $blankSql): void
    {
        $value = (string) $condition->value('value');

        match ($condition->operator) {
            SegmentOperator::Is => $query->whereRaw("lower({$expression}) = lower(?)", [$value]),
            SegmentOperator::IsNot => $query->whereRaw("({$expression} IS NULL OR lower({$expression}) <> lower(?))", [$value]),
            SegmentOperator::Contains => $query->whereRaw("{$expression} ILIKE ?", ['%'.$this->escapeLike($value).'%']),
            SegmentOperator::DoesNotContain => $query->whereRaw("({$expression} IS NULL OR {$expression} NOT ILIKE ?)", ['%'.$this->escapeLike($value).'%']),
            SegmentOperator::StartsWith => $query->whereRaw("{$expression} ILIKE ?", [$this->escapeLike($value).'%']),
            SegmentOperator::EndsWith => $query->whereRaw("{$expression} ILIKE ?", ['%'.$this->escapeLike($value)]),
            SegmentOperator::IsFromDomain => $query->whereRaw("lower(split_part({$expression}, '@', 2)) = lower(?)", [ltrim($value, '@')]),
            SegmentOperator::IsNotFromDomain => $query->whereRaw("({$expression} IS NULL OR lower(split_part({$expression}, '@', 2)) <> lower(?))", [ltrim($value, '@')]),
            SegmentOperator::IsBlank, SegmentOperator::IsNotBlank => $this->applyBlankOperator($query, $condition, $blankSql),
            default => throw $this->unsupported($condition),
        };
    }

    protected function applyNumberOperator(Builder $query, SegmentConditionData $condition, string $expression, string $blankSql): void
    {
        $comparison = match ($condition->operator) {
            SegmentOperator::EqualTo => '=',
            SegmentOperator::NotEqualTo => '<>',
            SegmentOperator::GreaterThan => '>',
            SegmentOperator::LessThan => '<',
            SegmentOperator::GreaterThanOrEqualTo => '>=',
            SegmentOperator::LessThanOrEqualTo => '<=',
            default => null,
        };

        if ($comparison === null) {
            $this->applyBlankOperator($query, $condition, $blankSql);

            return;
        }

        $query->whereRaw("{$expression} {$comparison} CAST(? AS numeric)", [(string) $condition->value('value')]);
    }

    protected function applyDateOperator(Builder $query, SegmentConditionData $condition, string $expression, string $blankSql): void
    {
        $date = fn (string $key): ?string => SegmentFieldCatalog::parseDate($condition->value($key))?->toDateString();
        $today = now()->startOfDay();

        match ($condition->operator) {
            SegmentOperator::Before => $query->whereRaw("{$expression} < CAST(? AS date)", [$date('value')]),
            SegmentOperator::After => $query->whereRaw("{$expression} > CAST(? AS date)", [$date('value')]),
            SegmentOperator::On => $query->whereRaw("{$expression} = CAST(? AS date)", [$date('value')]),
            SegmentOperator::OnOrBefore => $query->whereRaw("{$expression} <= CAST(? AS date)", [$date('value')]),
            SegmentOperator::OnOrAfter => $query->whereRaw("{$expression} >= CAST(? AS date)", [$date('value')]),
            SegmentOperator::Between => $query->whereRaw(
                "{$expression} BETWEEN CAST(? AS date) AND CAST(? AS date)",
                collect([$date('value'), $date('value_to')])->sort()->values()->all(),
            ),
            SegmentOperator::WithinLastDays => $query->whereRaw(
                "{$expression} BETWEEN CAST(? AS date) AND CAST(? AS date)",
                [$today->copy()->subDays((int) $condition->value('days'))->toDateString(), $today->toDateString()],
            ),
            SegmentOperator::MoreThanDaysAgo => $query->whereRaw(
                "{$expression} < CAST(? AS date)",
                [$today->copy()->subDays((int) $condition->value('days'))->toDateString()],
            ),
            SegmentOperator::MonthIs => $query->whereRaw("EXTRACT(MONTH FROM {$expression}) = ?", [(int) $condition->value('month')]),
            SegmentOperator::DayAndMonthIs => $query->whereRaw(
                "EXTRACT(MONTH FROM {$expression}) = ? AND EXTRACT(DAY FROM {$expression}) = ?",
                [(int) $condition->value('month'), (int) $condition->value('day')],
            ),
            SegmentOperator::IsBlank, SegmentOperator::IsNotBlank => $this->applyBlankOperator($query, $condition, $blankSql),
            default => throw $this->unsupported($condition),
        };
    }

    protected function applySelectOperator(Builder $query, SegmentConditionData $condition, string $expression, string $blankSql): void
    {
        $values = array_map('strval', $condition->values());
        $placeholders = implode(', ', array_fill(0, max(count($values), 1), '?'));

        match ($condition->operator) {
            SegmentOperator::Is => $query->whereRaw("{$expression} = ?", [(string) $condition->value('value')]),
            SegmentOperator::IsNot => $query->whereRaw("({$expression} IS NULL OR {$expression} <> ?)", [(string) $condition->value('value')]),
            SegmentOperator::IsAnyOf => $query->whereRaw("{$expression} IN ({$placeholders})", $values),
            SegmentOperator::IsNoneOf => $query->whereRaw("({$expression} IS NULL OR {$expression} NOT IN ({$placeholders}))", $values),
            SegmentOperator::IsBlank, SegmentOperator::IsNotBlank => $this->applyBlankOperator($query, $condition, $blankSql),
            default => throw $this->unsupported($condition),
        };
    }

    protected function applyBlankOperator(Builder $query, SegmentConditionData $condition, string $blankSql): void
    {
        match ($condition->operator) {
            SegmentOperator::IsBlank => $query->whereRaw($blankSql),
            SegmentOperator::IsNotBlank => $query->whereRaw("NOT ({$blankSql})"),
            default => throw $this->unsupported($condition),
        };
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    protected function unsupported(SegmentConditionData $condition): InvalidArgumentException
    {
        return new InvalidArgumentException("Operator [{$condition->operator->value}] is not supported for [{$condition->type->value}] conditions.");
    }
}
