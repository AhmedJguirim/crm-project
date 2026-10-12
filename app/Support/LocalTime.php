<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;

final class LocalTime
{
    /**
     * A stored (UTC) moment printed in the panel timezone (the current organization's) or in the given one.
     */
    public static function format(?CarbonInterface $moment, string $format, ?string $timezone = null): ?string
    {
        if ($moment === null) {
            return null;
        }

        return $moment->copy()->setTimezone($timezone ?? FilamentTimezone::get())->format($format);
    }
}
