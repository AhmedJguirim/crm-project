<?php

namespace App\Filament\Support\StateCasts;

use Carbon\CarbonInterface;
use Filament\Schemas\Components\StateCasts\DateTimeStateCast;

/**
 * Filament's date-picker state cast, made safe for forged state (BUG-43): an array or an object other than a date counts as empty instead of crashing the page. Bound in AppServiceProvider, so every DatePicker and DateTimePicker uses it.
 */
final class ScalarDateTimeStateCast extends DateTimeStateCast
{
    public function get(mixed $state): ?string
    {
        return self::isCastable($state) ? parent::get($state) : null;
    }

    public function set(mixed $state): ?string
    {
        return self::isCastable($state) ? parent::set($state) : null;
    }

    /**
     * Whether Filament's cast can read the state: an array or another object (a forged URL or Livewire update) cannot.
     */
    private static function isCastable(mixed $state): bool
    {
        return $state === null || is_scalar($state) || $state instanceof CarbonInterface;
    }
}
