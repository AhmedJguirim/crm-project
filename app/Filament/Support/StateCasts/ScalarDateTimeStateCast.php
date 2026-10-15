<?php

namespace App\Filament\Support\StateCasts;

use Carbon\CarbonInterface;
use Filament\Schemas\Components\StateCasts\DateTimeStateCast;

/**
 * Filament's date-picker state cast, made safe for forged state (BUG-43): an array or an object other than a date counts as empty instead of crashing the page, and a string whose calendar day doesn't exist (`2026-02-30`) counts as empty instead of rolling over to the next month (BUG-48). Bound in AppServiceProvider, so every DatePicker and DateTimePicker uses it.
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
     * Whether Filament's cast can read the state: an array or another object (a forged URL or Livewire update) cannot,
     * and neither can a string that starts like a `Y-m-d` date whose day doesn't exist, which Carbon would roll over.
     */
    private static function isCastable(mixed $state): bool
    {
        if (is_string($state) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $state, $parts) === 1 && ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return false;
        }

        return $state === null || is_scalar($state) || $state instanceof CarbonInterface;
    }
}
