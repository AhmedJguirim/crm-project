<?php

namespace App\Models\Concerns;

use App\Models\CompanyCustomField;
use App\Models\CustomField;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shared behaviour of models storing custom field values in `custom_field_values`,
 * keyed by the immutable key of each custom field definition.
 */
trait HasCustomFieldValues
{
    public function initializeHasCustomFieldValues(): void
    {
        $this->mergeCasts([
            'custom_field_values' => 'array',
        ]);

        $this->attributes['custom_field_values'] ??= '{}';
    }

    /** @return Collection<int, CustomField|CompanyCustomField> */
    abstract public function customFieldDefinitions(): Collection;

    public function customFieldValue(string $key): mixed
    {
        return $this->custom_field_values[$key] ?? null;
    }

    public function scopeWhereCustomFieldValue(Builder $query, string $key, mixed $value): Builder
    {
        $column = $query->qualifyColumn('custom_field_values');

        if (is_array($value)) {
            return $query->whereRaw("{$column}->? = CAST(? AS jsonb)", [$key, json_encode(array_values($value))]);
        }

        return $query->whereRaw("{$column}->>? = ?", [$key, (string) $value]);
    }
}
