<?php

namespace App\Filament\Support\StateCasts;

use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

/**
 * The state cast of a single record-id field (BUG-31): a real id comes out as an int, anything else as null.
 */
final class RecordKeyStateCast implements StateCast
{
    /**
     * The id held by a value: an int of at least 1, or a canonical decimal string of a positive 64-bit integer.
     */
    public static function toKey(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]{0,18}\z/', $value) !== 1) {
            return null;
        }

        $key = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($key) ? $key : null;
    }

    /**
     * What the schema, the closures and the hooks see: the id, or null for junk.
     */
    public function get(mixed $state): ?int
    {
        return self::toKey($state);
    }

    /**
     * What goes back to the form: the raw scalar as a string, junk included, so validation can refuse it.
     */
    public function set(mixed $state): ?string
    {
        if (blank($state) || ! is_scalar($state)) {
            return null;
        }

        return (string) $state;
    }
}
