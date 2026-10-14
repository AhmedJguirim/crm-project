<?php

namespace App\Filament\Support\Concerns;

/**
 * Keeps the table search of a Livewire table host a string (BUG-44). Filament calls strval() on the untyped
 * $tableSearch property, so an array coming from the URL (`?search[]=x`) or from a forged update is a 500.
 * Anything that is not a scalar becomes an empty search.
 */
trait SanitizesTableSearch
{
    public function bootSanitizesTableSearch(): void
    {
        $this->tableSearch = self::scalarTableSearch($this->tableSearch);
    }

    public function updatedSanitizesTableSearch(string $name): void
    {
        if ($name === 'tableSearch' || str_starts_with($name, 'tableSearch.')) {
            $this->tableSearch = self::scalarTableSearch($this->tableSearch);
        }
    }

    private static function scalarTableSearch(mixed $search): string
    {
        return is_scalar($search) ? (string) $search : '';
    }
}
