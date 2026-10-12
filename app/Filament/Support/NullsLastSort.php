<?php

namespace App\Filament\Support;

use Closure;
use Filament\Support\Services\RelationshipOrderer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Sort closures for `TextColumn::sortable(query: …)` that put empty values after all filled ones, ascending and
 * descending (PostgreSQL would put NULL first when descending). Filament injects the arguments by name.
 */
final class NullsLastSort
{
    /**
     * For a column of the table's own model: `->sortable(query: NullsLastSort::column('employees'))`.
     *
     * @return Closure(Builder, string): Builder
     */
    public static function column(string $column): Closure
    {
        return static fn (Builder $query, string $direction): Builder => $query->orderByRaw(
            $query->getQuery()->getGrammar()->wrap($query->qualifyColumn($column)).' '.self::direction($direction).' nulls last'
        );
    }

    /**
     * For a column of a to-one relationship, ordered through the same subquery Filament builds: a record without a
     * related row counts as empty.
     *
     * @return Closure(Builder, string): Builder
     */
    public static function relationship(string $relationship, string $column): Closure
    {
        return static function (Builder $query, string $direction) use ($relationship, $column): Builder {
            $subquery = app(RelationshipOrderer::class)->buildSubquery($query, $relationship, $column);

            return $query->orderByRaw('('.$subquery->toSql().') '.self::direction($direction).' nulls last', $subquery->getBindings());
        };
    }

    private static function direction(string $direction): string
    {
        return $direction === 'desc' ? 'desc' : 'asc';
    }
}
