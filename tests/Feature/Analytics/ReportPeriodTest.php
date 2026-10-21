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

test('a trail has six periods of the preset, oldest first, the period itself last', function (ReportPeriodPreset $preset, array $starts, string $firstEnd) {
    $period = ReportPeriod::forPreset($preset, 'Europe/Paris');
    $trail = $period->trail(6);

    expect(array_map(fn (ReportPeriod $step): string => $step->startDate, $trail))->toBe($starts)
        ->and($trail[0]->endDate)->toBe($firstEnd)
        ->and($trail[5])->toEqual($period)
        ->and($trail[5]->preset)->toBe($preset);

    foreach (array_slice($trail, 0, 5) as $earlier) {
        expect($earlier->preset)->toBe(ReportPeriodPreset::Custom)->and($earlier->timezone)->toBe('Europe/Paris');
    }

    for ($index = 1; $index < 6; $index++) {
        expect(CarbonImmutable::parse($trail[$index - 1]->endDate)->addDay()->toDateString())->toBe($trail[$index]->startDate);
    }
})->with([
    'today' => [ReportPeriodPreset::Today, ['2026-10-16', '2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21'], '2026-10-16'],
    'this week' => [ReportPeriodPreset::ThisWeek, ['2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19'], '2026-09-20'],
    'this month' => [ReportPeriodPreset::ThisMonth, ['2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-10-01'], '2026-05-31'],
    'last month' => [ReportPeriodPreset::LastMonth, ['2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'], '2026-04-30'],
    'this quarter' => [ReportPeriodPreset::ThisQuarter, ['2025-07-01', '2025-10-01', '2026-01-01', '2026-04-01', '2026-07-01', '2026-10-01'], '2025-09-30'],
    'this year' => [ReportPeriodPreset::ThisYear, ['2021-01-01', '2022-01-01', '2023-01-01', '2024-01-01', '2025-01-01', '2026-01-01'], '2021-12-31'],
    'last 12 months' => [ReportPeriodPreset::Last12Months, ['2020-11-01', '2021-11-01', '2022-11-01', '2023-11-01', '2024-11-01', '2025-11-01'], '2021-10-31'],
]);

test('a custom range steps back by its own length', function () {
    $trail = ReportPeriod::custom('2026-10-01', '2026-10-15', 'Europe/Paris')->trail(6);

    expect(array_map(fn (ReportPeriod $step): string => $step->startDate.'..'.$step->endDate, $trail))->toBe([
        '2026-07-18..2026-08-01',
        '2026-08-02..2026-08-16',
        '2026-08-17..2026-08-31',
        '2026-09-01..2026-09-15',
        '2026-09-16..2026-09-30',
        '2026-10-01..2026-10-15',
    ]);
});

test('the second-to-last period of a trail is the previous period', function (callable $period) {
    $period = $period();

    expect($period->trail(2)[0]->dates())->toBe($period->previous()->dates());
})->with([
    'today' => [fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::Today, 'Europe/Paris')],
    'this week' => [fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::ThisWeek, 'Europe/Paris')],
    'this month' => [fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris')],
    'last month' => [fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::LastMonth, 'Europe/Paris')],
    'this quarter' => [fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::ThisQuarter, 'Europe/Paris')],
    'last quarter' => [fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::LastQuarter, 'Europe/Paris')],
    'this year' => [fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::ThisYear, 'Europe/Paris')],
    'last 12 months' => [fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::Last12Months, 'Europe/Paris')],
    'a custom range' => [fn (): ReportPeriod => ReportPeriod::custom('2026-10-01', '2026-10-15', 'Europe/Paris')],
]);

test('a trail of one is the period itself and a trail of zero is refused', function () {
    $period = ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris');

    expect($period->trail(1))->toEqual([$period]);

    $period->trail(0);
})->throws(InvalidArgumentException::class, 'A trail needs at least one period.');
