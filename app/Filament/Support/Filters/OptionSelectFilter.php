<?php

namespace App\Filament\Support\Filters;

use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A table filter over fixed options (BUG-32): a value that is not one of the options is dropped before Filament queries with it.
 */
final class OptionSelectFilter extends SelectFilter
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<TModel>
     */
    public function apply(Builder $query, array $data = []): Builder
    {
        return parent::apply($query, $this->cleanState($data));
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        return $this->cleanState(parent::getState());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cleanState(array $data): array
    {
        $known = array_map('strval', array_keys($this->getOptions()));
        $isKnown = fn (mixed $item): bool => (is_string($item) || is_int($item)) && in_array((string) $item, $known, true);

        if ($this->isMultiple()) {
            $values = $data['values'] ?? null;

            $data['values'] = is_array($values)
                ? array_values(array_map('strval', array_filter($values, $isKnown)))
                : [];

            return $data;
        }

        $value = $data['value'] ?? null;
        $data['value'] = $isKnown($value) ? (string) $value : null;

        return $data;
    }
}
