<?php

namespace App\Filament\Support\StateCasts;

use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

/**
 * The state cast of a multiple record-id field (BUG-31): the real ids come out, the junk is dropped.
 */
final class RecordKeysStateCast implements StateCast
{
    /**
     * @return list<int>
     */
    public function get(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(RecordKeyStateCast::toKey(...), $state))));
    }

    /**
     * @return list<string>
     */
    public function set(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $item): string => (string) $item,
            array_filter($state, fn (mixed $item): bool => is_scalar($item) && filled($item)),
        ));
    }
}
