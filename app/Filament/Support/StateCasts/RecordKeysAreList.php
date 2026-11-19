<?php

namespace App\Filament\Support\StateCasts;

use App\Support\Security\SecurityLog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses a scalar sent where a multi-select of record ids expects a list, instead of reading it as no selection, and logs it (SEC-65).
 */
final class RecordKeysAreList implements ValidationRule
{
    public const string GUARD = 'record_key_list';

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_array($value)) {
            return;
        }

        SecurityLog::forgedValue(self::GUARD);
        $fail('validation.array')->translate();
    }
}
