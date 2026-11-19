<?php

namespace App\Filament\Support\StateCasts;

use App\Support\Security\SecurityLog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses any value RecordKeyStateCast::toKey() reads as no id ("abc", " 5", "+5"), so a forged value is never silently dropped (BUG-31) and is logged (SEC-64, SEC-65).
 */
final class RecordKeyIsReadable implements ValidationRule
{
    public const string GUARD = 'record_key';

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (RecordKeyStateCast::toKey($value) !== null) {
            return;
        }

        SecurityLog::forgedValue(self::GUARD);
        $fail('validation.integer')->translate();
    }
}
