<?php

namespace App\Filament\Support\StateCasts;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses what the `integer` rule lets through but RecordKeyStateCast::toKey() reads as no id (" 5", "5 ", "+5"), so a forged value is never silently dropped (BUG-31).
 */
final class RecordKeyIsReadable implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (RecordKeyStateCast::toKey($value) !== null) {
            return;
        }

        $fail('validation.integer')->translate();
    }
}
