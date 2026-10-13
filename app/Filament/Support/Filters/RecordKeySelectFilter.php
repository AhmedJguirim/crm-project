<?php

namespace App\Filament\Support\Filters;

use App\Filament\Support\StateCasts\RecordKeysStateCast;
use App\Filament\Support\StateCasts\RecordKeyStateCast;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A table filter on record ids (BUG-32): a forged value is dropped before Filament queries with it; see RecordKeyField for form fields.
 */
final class RecordKeySelectFilter extends SelectFilter
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

    public function getFormField(): Select
    {
        return parent::getFormField()->stateCast($this->isMultiple() ? new RecordKeysStateCast : new RecordKeyStateCast);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function cleanState(array $data): array
    {
        if ($this->isMultiple()) {
            $data['values'] = array_map('strval', (new RecordKeysStateCast)->get($data['values'] ?? null));

            return $data;
        }

        $key = RecordKeyStateCast::toKey($data['value'] ?? null);
        $data['value'] = $key === null ? null : (string) $key;

        return $data;
    }
}
