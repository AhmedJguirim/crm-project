<?php

use App\Enums\ReportPeriodPreset;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));
});

test('a preset gives its local dates and the period before it', function (ReportPeriodPreset $preset, string $start, string $end, string $previousStart, string $previousEnd) {
    $period = ReportPeriod::forPreset($preset, 'Europe/Paris');

    expect($period->dates())->toBe([$start, $end])
        ->and($period->previous()->dates())->toBe([$previousStart, $previousEnd])
        ->and($period->previous()->preset)->toBe(ReportPeriodPreset::Custom)
        ->and($period->previous()->timezone)->toBe('Europe/Paris');
})->with([
    'today' => [ReportPeriodPreset::Today, '2026-10-21', '2026-10-21', '2026-10-20', '2026-10-20'],
    'this week' => [ReportPeriodPreset::ThisWeek, '2026-10-19', '2026-10-25', '2026-10-12', '2026-10-18'],
    'this month' => [ReportPeriodPreset::ThisMonth, '2026-10-01', '2026-10-31', '2026-09-01', '2026-09-30'],
    'last month' => [ReportPeriodPreset::LastMonth, '2026-09-01', '2026-09-30', '2026-08-01', '2026-08-31'],
    'this quarter' => [ReportPeriodPreset::ThisQuarter, '2026-10-01', '2026-12-31', '2026-07-01', '2026-09-30'],
    'last quarter' => [ReportPeriodPreset::LastQuarter, '2026-07-01', '2026-09-30', '2026-04-01', '2026-06-30'],
    'this year' => [ReportPeriodPreset::ThisYear, '2026-01-01', '2026-12-31', '2025-01-01', '2025-12-31'],
    'last 12 months' => [ReportPeriodPreset::Last12Months, '2025-11-01', '2026-10-31', '2024-11-01', '2025-10-31'],
]);

test('the UTC bounds follow the local days, daylight saving included', function (ReportPeriodPreset $preset, string $startUtc, string $endUtc) {
    [$start, $end] = ReportPeriod::forPreset($preset, 'Europe/Paris')->bounds();

    expect($start->format('Y-m-d H:i:s'))->toBe($startUtc)
        ->and($end->format('Y-m-d H:i:s'))->toBe($endUtc)
        ->and($start->getTimezone()->getName())->toBe('UTC');
})->with([
    'today' => [ReportPeriodPreset::Today, '2026-10-20 22:00:00', '2026-10-21 21:59:59'],
    'this week' => [ReportPeriodPreset::ThisWeek, '2026-10-18 22:00:00', '2026-10-25 22:59:59'],
    'this month' => [ReportPeriodPreset::ThisMonth, '2026-09-30 22:00:00', '2026-10-31 22:59:59'],
]);

test('today is the local day, not the UTC day', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 23:30:00', 'UTC'));

    expect(ReportPeriod::forPreset(ReportPeriodPreset::Today, 'Europe/Paris')->dates())->toBe(['2026-10-22', '2026-10-22']);
});

test('last month on March 31 does not overflow', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00', 'UTC'));

    expect(ReportPeriod::forPreset(ReportPeriodPreset::LastMonth, 'Europe/Paris')->dates())->toBe(['2026-02-01', '2026-02-28']);

    [$start, $end] = ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris')->bounds();

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-02-28 23:00:00')
        ->and($end->format('Y-m-d H:i:s'))->toBe('2026-03-31 21:59:59');
});

test('the last quarter of January is in the previous year', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-15 10:00:00', 'UTC'));

    expect(ReportPeriod::forPreset(ReportPeriodPreset::LastQuarter, 'Europe/Paris')->dates())->toBe(['2025-10-01', '2025-12-31']);
});

test('a custom range is swapped when reversed and has a previous period of the same length', function () {
    $period = ReportPeriod::custom('2026-10-15', '2026-10-01', 'Europe/Paris');

    expect($period->dates())->toBe(['2026-10-01', '2026-10-15'])
        ->and($period->preset)->toBe(ReportPeriodPreset::Custom)
        ->and($period->previous()->dates())->toBe(['2026-09-16', '2026-09-30'])
        ->and($period->title())->toBe('In Period')
        ->and($period->phrase())->toBe('from Oct 1, 2026 to Oct 15, 2026')
        ->and(ReportPeriod::custom('2026-10-05', '2026-10-05', 'Europe/Paris')->previous()->dates())->toBe(['2026-10-04', '2026-10-04'])
        ->and(ReportPeriod::custom('2026-03-20', '2026-03-30', 'Europe/Paris')->previous()->dates())->toBe(['2026-03-09', '2026-03-19']);
});

test('a custom range uses the panel timezone when none is given', function () {
    expect(ReportPeriod::custom('2026-10-01', '2026-10-02')->timezone)->toBe(config('app.timezone'));
});

test('titles and phrases', function () {
    expect(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris'))
        ->title()->toBe('This Month')
        ->phrase()->toBe('this month')
        ->and(ReportPeriod::forPreset(ReportPeriodPreset::Last12Months, 'Europe/Paris'))
        ->title()->toBe('Last 12 Months')
        ->phrase()->toBe('in the last 12 months')
        ->and(ReportPeriod::forPreset(ReportPeriodPreset::Today, 'Europe/Paris'))
        ->title()->toBe('Today')
        ->phrase()->toBe('today');
});

test('every preset but custom has a title and a phrase', function () {
    foreach (ReportPeriodPreset::cases() as $preset) {
        if ($preset === ReportPeriodPreset::Custom) {
            continue;
        }

        $period = ReportPeriod::forPreset($preset, 'UTC');

        expect($period->title())->not->toBe('In Period')
            ->and($period->phrase())->not->toStartWith('from ')
            ->and($preset->getLabel())->toBeString();
    }
});

test('forPreset refuses the custom preset', function () {
    ReportPeriod::forPreset(ReportPeriodPreset::Custom);
})->throws(InvalidArgumentException::class, 'Use ReportPeriod::custom() for a custom range.');
