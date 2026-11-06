<?php

namespace App\Support\Reports;

use App\Enums\ReportPeriodPreset;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;

/**
 * The dashboard filters (period and owner) as safe values. The page filters travel in the URL, the session and the
 * Livewire payload, so they are forged input: every report widget reads them only through `fromPageFilters()`, which
 * turns anything invalid (a custom date that is not a real day or is outside 1900–2100 included) into the defaults (this month, everyone) and keeps an owner only when they are a current
 * member of the organization.
 */
final readonly class DashboardFilters
{
    private function __construct(
        public ReportPeriod $period,
        public ?int $ownerId,
    ) {}

    /**
     * @param  mixed  $filters  the `pageFilters` of a widget or the `filters` of the dashboard page
     */
    public static function fromPageFilters(mixed $filters, ?string $timezone = null): self
    {
        $filters = is_array($filters) ? $filters : [];

        return new self(self::periodFrom($filters, $timezone), self::ownerFrom($filters['owner'] ?? null));
    }

    /**
     * @param  array<array-key, mixed>  $filters
     */
    private static function periodFrom(array $filters, ?string $timezone): ReportPeriod
    {
        $value = $filters['period'] ?? null;

        $preset = match (true) {
            $value instanceof ReportPeriodPreset => $value,
            is_string($value) => ReportPeriodPreset::tryFrom($value),
            default => null,
        } ?? ReportPeriodPreset::ThisMonth;

        if ($preset !== ReportPeriodPreset::Custom) {
            return ReportPeriod::forPreset($preset, $timezone);
        }

        $from = self::customDate($filters['from'] ?? null);
        $to = self::customDate($filters['to'] ?? null);

        if ($from === null || $to === null) {
            return ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, $timezone);
        }

        return ReportPeriod::custom($from, $to, $timezone);
    }

    /**
     * A custom date as `Y-m-d` when it is a real day between `ReportPeriod::EARLIEST_DATE` and `LATEST_DATE`, else null.
     * The year always has 4 digits after `LocalTime::filterDate()`, so comparing the strings compares the days.
     */
    private static function customDate(mixed $value): ?string
    {
        $date = LocalTime::filterDate($value);

        if ($date === null || $date < ReportPeriod::EARLIEST_DATE || $date > ReportPeriod::LATEST_DATE) {
            return null;
        }

        return $date;
    }

    private static function ownerFrom(mixed $value): ?int
    {
        $id = match (true) {
            $value === 'me' => auth()->id(),
            is_int($value) && $value > 0 => $value,
            is_string($value) && preg_match('/^[1-9][0-9]{0,18}\z/', $value) === 1 => (int) $value,
            default => null,
        };

        if (! is_int($id)) {
            return null;
        }

        $isMember = CurrentOrganization::find()?->members()->where('users.id', $id)->exists() ?? false;

        return $isMember ? $id : null;
    }
}
