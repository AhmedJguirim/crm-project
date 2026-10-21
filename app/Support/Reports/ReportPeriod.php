<?php

namespace App\Support\Reports;

use App\Enums\ReportPeriodPreset;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use InvalidArgumentException;

/**
 * A reporting period made of whole local days (inclusive `Y-m-d` start and end in the organization's timezone), built
 * from a preset or a custom range. It gives the dates for date columns, the UTC bounds for timestamp columns, the
 * period of the same length just before it, and the wording used by report widgets.
 */
final readonly class ReportPeriod
{
    private function __construct(
        public ReportPeriodPreset $preset,
        public string $startDate,
        public string $endDate,
        public string $timezone,
    ) {}

    /**
     * The period of a preset that contains now in the given timezone (default: the panel's).
     *
     * @throws InvalidArgumentException for the custom preset
     */
    public static function forPreset(ReportPeriodPreset $preset, ?string $timezone = null): self
    {
        $timezone ??= FilamentTimezone::get();
        $now = LocalTime::now($timezone);

        [$start, $end] = match ($preset) {
            ReportPeriodPreset::Today => [$now, $now],
            ReportPeriodPreset::ThisWeek => [$now->startOfWeek(), $now->endOfWeek()],
            ReportPeriodPreset::ThisMonth => [$now->startOfMonth(), $now->endOfMonth()],
            ReportPeriodPreset::LastMonth => [$now->startOfMonth()->subMonthNoOverflow(), $now->startOfMonth()->subMonthNoOverflow()->endOfMonth()],
            ReportPeriodPreset::ThisQuarter => [$now->startOfQuarter(), $now->endOfQuarter()],
            ReportPeriodPreset::LastQuarter => [$now->startOfQuarter()->subMonthsNoOverflow(3), $now->startOfQuarter()->subMonthsNoOverflow(3)->endOfQuarter()],
            ReportPeriodPreset::ThisYear => [$now->startOfYear(), $now->endOfYear()],
            ReportPeriodPreset::Last12Months => [$now->startOfMonth()->subMonthsNoOverflow(11), $now->endOfMonth()],
            ReportPeriodPreset::Custom => throw new InvalidArgumentException('Use ReportPeriod::custom() for a custom range.'),
        };

        return new self($preset, $start->toDateString(), $end->toDateString(), $timezone);
    }

    /**
     * A custom range of two local dates already checked by `LocalTime::filterDate()`; swapped when the first is after the second.
     */
    public static function custom(string $fromDate, string $toDate, ?string $timezone = null): self
    {
        [$from, $to] = $fromDate <= $toDate ? [$fromDate, $toDate] : [$toDate, $fromDate];

        return new self(ReportPeriodPreset::Custom, $from, $to, $timezone ?? FilamentTimezone::get());
    }

    /**
     * The first and last local day, for date columns.
     *
     * @return array{0: string, 1: string}
     */
    public function dates(): array
    {
        return [$this->startDate, $this->endDate];
    }

    /**
     * The first and last moment of the period as UTC bounds for timestamp columns, DST included, end inclusive.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(): array
    {
        return [
            LocalTime::dayBounds($this->startDate, $this->timezone)[0],
            LocalTime::dayBounds($this->endDate, $this->timezone)[1],
        ];
    }

    /**
     * The period just before this one, always ending the day before this one starts: the previous day, week, month,
     * quarter, year or 12 months for the presets, and as many days as this range has for a custom range.
     */
    public function previous(): self
    {
        [$start, $end] = $this->stepBack($this->startDate, $this->endDate);

        return new self(ReportPeriodPreset::Custom, $start, $end, $this->timezone);
    }

    /**
     * The given number of consecutive periods ending with this one, oldest first, each one step back from the next in
     * the unit of this period's preset (so a month-based trail does not drift). The earlier periods are custom ranges.
     *
     * @return list<self>
     *
     * @throws InvalidArgumentException when fewer than one period is asked
     */
    public function trail(int $count): array
    {
        if ($count < 1) {
            throw new InvalidArgumentException('A trail needs at least one period.');
        }

        $periods = [$this];
        [$start, $end] = $this->dates();

        for ($step = 1; $step < $count; $step++) {
            [$start, $end] = $this->stepBack($start, $end);
            array_unshift($periods, new self(ReportPeriodPreset::Custom, $start, $end, $this->timezone));
        }

        return $periods;
    }

    /**
     * The dates of the period just before the one given, stepping back in the unit of this period's preset.
     *
     * @return array{0: string, 1: string}
     */
    private function stepBack(string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $startDate, 'UTC');
        $end = $start->subDay();

        $previousStart = match ($this->preset) {
            ReportPeriodPreset::Today => $end,
            ReportPeriodPreset::ThisWeek => $start->subDays(7),
            ReportPeriodPreset::ThisMonth, ReportPeriodPreset::LastMonth => $start->subMonthNoOverflow(),
            ReportPeriodPreset::ThisQuarter, ReportPeriodPreset::LastQuarter => $start->subMonthsNoOverflow(3),
            ReportPeriodPreset::ThisYear => $start->subYear(),
            ReportPeriodPreset::Last12Months => $start->subMonthsNoOverflow(12),
            ReportPeriodPreset::Custom => $end->subDays((int) $start->diffInDays(CarbonImmutable::createFromFormat('!Y-m-d', $endDate, 'UTC'))),
        };

        return [$previousStart->toDateString(), $end->toDateString()];
    }

    /**
     * The short name used in labels, such as "This Month" ("In Period" for a custom range).
     */
    public function title(): string
    {
        return match ($this->preset) {
            ReportPeriodPreset::Today => 'Today',
            ReportPeriodPreset::ThisWeek => 'This Week',
            ReportPeriodPreset::ThisMonth => 'This Month',
            ReportPeriodPreset::LastMonth => 'Last Month',
            ReportPeriodPreset::ThisQuarter => 'This Quarter',
            ReportPeriodPreset::LastQuarter => 'Last Quarter',
            ReportPeriodPreset::ThisYear => 'This Year',
            ReportPeriodPreset::Last12Months => 'Last 12 Months',
            ReportPeriodPreset::Custom => 'In Period',
        };
    }

    /**
     * The wording used in descriptions, such as "this month" or "from Oct 1, 2026 to Oct 15, 2026".
     */
    public function phrase(): string
    {
        return match ($this->preset) {
            ReportPeriodPreset::Today => 'today',
            ReportPeriodPreset::ThisWeek => 'this week',
            ReportPeriodPreset::ThisMonth => 'this month',
            ReportPeriodPreset::LastMonth => 'last month',
            ReportPeriodPreset::ThisQuarter => 'this quarter',
            ReportPeriodPreset::LastQuarter => 'last quarter',
            ReportPeriodPreset::ThisYear => 'this year',
            ReportPeriodPreset::Last12Months => 'in the last 12 months',
            ReportPeriodPreset::Custom => 'from '.$this->formatDate($this->startDate).' to '.$this->formatDate($this->endDate),
        };
    }

    private function formatDate(string $date): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->format('M j, Y');
    }
}
