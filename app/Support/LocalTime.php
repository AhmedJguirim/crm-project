<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Stored moments are UTC; this class prints them in the panel timezone (the current organization's) and gives the
 * local day and the local periods (day, week, month, year) as UTC bounds for timestamp columns or as dates for date
 * columns. Every method takes an optional timezone for callers outside the panel.
 */
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

    /**
     * The current moment in the panel timezone or in the given one.
     */
    public static function now(?string $timezone = null): CarbonImmutable
    {
        return CarbonImmutable::now($timezone ?? FilamentTimezone::get());
    }

    /**
     * Today's local date as `Y-m-d`, for date columns.
     */
    public static function todayDate(?string $timezone = null): string
    {
        return self::now($timezone)->toDateString();
    }

    /**
     * The local day, week (Monday to Sunday), month or year containing now, as UTC bounds for timestamp columns.
     *
     * @param  'day'|'week'|'month'|'year'  $unit
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(string $unit, ?string $timezone = null): array
    {
        [$start, $end] = self::localBounds($unit, $timezone);

        return [$start->utc(), $end->utc()];
    }

    /**
     * The local day, week (Monday to Sunday), month or year containing now, as `Y-m-d` dates for date columns.
     *
     * @param  'day'|'week'|'month'|'year'  $unit
     * @return array{0: string, 1: string}
     */
    public static function periodDates(string $unit, ?string $timezone = null): array
    {
        [$start, $end] = self::localBounds($unit, $timezone);

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * A filter value is only used when it is a real `Y-m-d` date (BUG-32); anything else, forged or partial, counts as empty.
     */
    public static function filterDate(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }

        return checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)) ? $value : null;
    }

    /**
     * The first and last moment of a local day (a `Y-m-d` date already checked by `filterDate()`) as UTC bounds for
     * timestamp columns, DST included. The end is inclusive, like the periods of `period()`.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function dayBounds(string $date, ?string $timezone = null): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone ?? FilamentTimezone::get());

        return [$start->utc(), $start->endOfDay()->utc()];
    }

    /**
     * @param  'day'|'week'|'month'|'year'  $unit
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function localBounds(string $unit, ?string $timezone): array
    {
        $now = self::now($timezone);

        return match ($unit) {
            'day' => [$now->startOfDay(), $now->endOfDay()],
            'week' => [$now->startOfWeek(), $now->endOfWeek()],
            'month' => [$now->startOfMonth(), $now->endOfMonth()],
            'year' => [$now->startOfYear(), $now->endOfYear()],
        };
    }
}
