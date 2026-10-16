<?php

namespace App\Filament\Support\StateCasts;

use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

/**
 * Reads a forged array as empty so a field whose view prints its state (the color picker) renders the validation error
 * instead of crashing the page (BUG-50). The array itself is still refused by SingleValueFieldState, which reads the raw state.
 */
final class ScalarStateCast implements StateCast
{
    public function get(mixed $state): mixed
    {
        return is_array($state) ? null : $state;
    }

    public function set(mixed $state): mixed
    {
        return is_array($state) ? null : $state;
    }
}
