<?php

use App\Enums\DealStatus;
use App\Enums\ReportPeriodPreset;
use App\Models\DealStageChange;
use App\Services\Deals\DefaultPipeline;
use App\Services\Reports\TimeInStage;
use App\Support\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Tests\Feature\Analytics\StageConversionFixture;
use Tests\Feature\Analytics\TimeInStageFixture;
use Tests\Feature\Security\SecurityPayloads;

beforeEach(function () {
    $this->travelTo(StageConversionFixture::now());
    $this->team = TimeInStageFixture::build();
    $this->stageId = fn (string $name): int => DefaultPipeline::stagesOf($this->team->org->id)->firstWhere('name', $name)->id;
    $this->figures = fn (array $rows): array => array_values(array_map(
        fn (array $row): array => [$row['stage'], $row['average_days'], $row['stays'], $row['in_stage_now'], $row['over_average']],
        $rows,
    ));
});

test('this month, everyone', function () {
    $rows = TimeInStage::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris'), null);

    expect(($this->figures)($rows))->toBe([
        ['Lead', '2.9', 5, 3, '1'],
        ['Discovery', '3.0', 2, 2, '2'],
        ['Proposal Sent', '6.0', 1, 1, '1'],
        ['Negotiating', '—', 0, 1, '—'],
    ]);
});

test('last month keeps the period edge in the organization timezone', function () {
    $rows = TimeInStage::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::LastMonth, 'Europe/Paris'), null);

    expect(($this->figures)($rows))->toBe([
        ['Lead', '9.0', 1, 3, '1'],
        ['Discovery', '—', 0, 2, '—'],
        ['Proposal Sent', '—', 0, 1, '—'],
        ['Negotiating', '—', 0, 1, '—'],
    ]);
});

test('the owner filter applies to the stays and to the open deals', function () {
    $rows = TimeInStage::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris'), $this->team->bob->id);

    expect(($this->figures)($rows))->toBe([
        ['Lead', '3.3', 2, 1, '1'],
        ['Discovery', '2.0', 1, 0, '0'],
        ['Proposal Sent', '—', 0, 0, '—'],
        ['Negotiating', '—', 0, 0, '—'],
    ]);
});

test('a history row of a deleted stage ends the stay before it', function () {
    $team = StageConversionFixture::build(withDeals: false);
    $deal = $team->deal('X', '2026-10-01 08:00:00', 'Lead', null, [['2026-10-02 08:00:00', 'Discovery'], ['2026-10-03 08:00:00', 'Proposal Sent']]);
    DealStageChange::query()->where('deal_id', $deal->id)->where('to_pipeline_stage_id', DefaultPipeline::stagesOf($team->org->id)->firstWhere('name', 'Discovery')->id)->update(['to_pipeline_stage_id' => null]);
    $this->travelTo(StageConversionFixture::now());

    $rows = TimeInStage::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris'), null);

    expect(($this->figures)($rows))->toBe([
        ['Lead', '1.0', 1, 0, '0'],
        ['Discovery', '—', 0, 0, '—'],
        ['Proposal Sent', '—', 0, 1, '—'],
        ['Negotiating', '—', 0, 0, '—'],
    ]);
});

test('a deal without history is never over the average', function () {
    $rows = TimeInStage::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris'), $this->team->alice->id);

    expect($rows['stage-'.($this->stageId)('Lead')])->toMatchArray(['stays' => 3, 'in_stage_now' => 2, 'over_average' => '1']);
});

test('no current organization gives no rows', function () {
    Filament::setTenant(null);

    expect(TimeInStage::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'UTC'), null))->toBe([]);
});

test('rows use the current stage names and order', function () {
    DefaultPipeline::stagesOf($this->team->org->id)->firstWhere('name', 'Lead')->update(['name' => 'Inbound']);

    $rows = TimeInStage::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris'), null);

    expect(array_keys($rows))->toBe(['stage-'.($this->stageId)('Inbound'), 'stage-'.($this->stageId)('Discovery'), 'stage-'.($this->stageId)('Proposal Sent'), 'stage-'.($this->stageId)('Negotiating')])
        ->and(array_values($rows)[0]['stage'])->toBe('Inbound');
});

test('a deal that is not open is not counted in its stage', function () {
    $this->team->deals['V4']->update(['status' => DealStatus::Lost]);

    $rows = TimeInStage::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris'), null);

    expect($rows['stage-'.($this->stageId)('Lead')])->toMatchArray(['in_stage_now' => 2, 'over_average' => '0']);
});

test('the number of queries does not grow with the deals', function () {
    $period = ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris');
    TimeInStage::forPeriod($period, null);

    $before = count(SecurityPayloads::queriesDuring(fn () => TimeInStage::forPeriod($period, null)));

    foreach (range(1, 3) as $number) {
        $this->team->deal("Extra {$number}", '2026-10-02 08:00:00', 'Lead', $this->team->alice);
    }
    $this->travelTo(StageConversionFixture::now());

    expect(count(SecurityPayloads::queriesDuring(fn () => TimeInStage::forPeriod($period, null))))->toBe($before);
});
