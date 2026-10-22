<?php

use App\Enums\DealStatus;
use App\Enums\ReportPeriodPreset;
use App\Models\DealStageChange;
use App\Models\Organization;
use App\Services\Deals\DefaultPipeline;
use App\Services\Deals\PipelineStageOrder;
use App\Services\Reports\StageConversion;
use App\Support\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Tests\Feature\Analytics\StageConversionFixture;

beforeEach(function () {
    $this->travelTo(StageConversionFixture::now());
    $this->team = StageConversionFixture::build();
    $this->stageId = fn (string $name): int => DefaultPipeline::stagesOf($this->team->org->id)->firstWhere('name', $name)->id;
    $this->thisMonth = fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth);
    $this->lastMonth = fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::LastMonth);
    $this->figures = fn (array $result): array => array_values(array_map(
        fn (array $row): array => [$row['stage'], $row['deals'], $row['of_created'], $row['from_previous'], $row['is_won']],
        $result['rows'],
    ));
});

test('the cohort and the rows for this month, everyone', function () {
    $result = StageConversion::forPeriod(($this->thisMonth)(), null);

    expect($result['deals_created'])->toBe(6)
        ->and(array_keys($result['rows']))->toBe([
            'stage-'.($this->stageId)('Lead'), 'stage-'.($this->stageId)('Discovery'), 'stage-'.($this->stageId)('Proposal Sent'), 'stage-'.($this->stageId)('Negotiating'), 'won',
        ])
        ->and(($this->figures)($result))->toBe([
            ['Lead', 5, '83%', '—', false],
            ['Discovery', 4, '67%', '80%', false],
            ['Proposal Sent', 3, '50%', '75%', false],
            ['Negotiating', 2, '33%', '67%', false],
            ['Won', 1, '17%', '50%', true],
        ]);
});

test('the owner filter narrows the cohort', function () {
    $result = StageConversion::forPeriod(($this->thisMonth)(), $this->team->bob->id);

    expect($result['deals_created'])->toBe(2)
        ->and(array_column($result['rows'], 'deals'))->toBe([2, 2, 1, 0, 0])
        ->and(array_column($result['rows'], 'of_created'))->toBe(['100%', '100%', '50%', '0%', '0%'])
        ->and(array_column($result['rows'], 'from_previous'))->toBe(['—', '100%', '50%', '0%', '—']);
});

test('a deal without history counts at its current stage', function () {
    $deal = $this->team->deal('Old', '2026-10-09 09:00:00', 'Proposal Sent', $this->team->alice);
    DealStageChange::query()->where('deal_id', $deal->id)->delete();

    expect(DealStageChange::query()->where('deal_id', $deal->id)->count())->toBe(0);

    $result = StageConversion::forPeriod(($this->thisMonth)(), null);

    expect($result['deals_created'])->toBe(7)
        ->and(array_column($result['rows'], 'deals'))->toBe([6, 5, 4, 2, 1]);
});

test('renamed and deleted stages follow the pipeline as it is now', function () {
    $organization = Organization::factory()->create(['timezone' => 'Europe/Paris']);
    Filament::setTenant($organization);
    $team = $this->team;
    $team->org = $organization;
    $team->deal('X', '2026-10-02 09:00:00', 'Lead', null, [['2026-10-03 09:00:00', 'Discovery'], ['2026-10-04 09:00:00', 'Proposal Sent']], $organization);
    $team->deal('Y', '2026-10-02 10:00:00', 'Lead', null, [['2026-10-03 10:00:00', 'Discovery']], $organization);
    $this->travelTo(StageConversionFixture::now());

    $stages = DefaultPipeline::stagesOf($organization->id);
    $stages->firstWhere('name', 'Proposal Sent')->update(['name' => 'Quote sent']);
    PipelineStageOrder::deleteOpenStage(DefaultPipeline::ensureFor($organization), $stages->firstWhere('name', 'Discovery'), $stages->firstWhere('name', 'Lead')->id);

    $result = StageConversion::forPeriod(($this->thisMonth)(), null);

    expect($result['deals_created'])->toBe(2)
        ->and(($this->figures)($result))->toBe([
            ['Lead', 2, '100%', '—', false],
            ['Quote sent', 1, '50%', '50%', false],
            ['Negotiating', 0, '0%', '0%', false],
            ['Won', 0, '0%', '—', true],
        ]);
});

test('the month boundary follows the organization timezone', function () {
    $result = StageConversion::forPeriod(($this->lastMonth)(), null);

    expect($result['deals_created'])->toBe(1)
        ->and(($this->figures)($result))->toBe([
            ['Lead', 1, '100%', '—', false],
            ['Discovery', 1, '100%', '100%', false],
            ['Proposal Sent', 1, '100%', '100%', false],
            ['Negotiating', 1, '100%', '100%', false],
            ['Won', 1, '100%', '100%', true],
        ]);
});

test('an empty cohort has no rows', function () {
    expect(StageConversion::forPeriod(ReportPeriod::custom('2026-06-01', '2026-06-30', 'Europe/Paris'), null))
        ->toBe(['deals_created' => 0, 'rows' => []]);
});

test('without a current organization there is nothing to report', function () {
    Filament::setTenant(null);

    expect(StageConversion::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris'), null))
        ->toBe(['deals_created' => 0, 'rows' => []]);
});

test('another organization is never counted and never reads this one', function () {
    Filament::setTenant($this->team->globex);

    $result = StageConversion::forPeriod(ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth, 'Europe/Paris'), null);

    expect($result['deals_created'])->toBe(1)
        ->and(array_column($result['rows'], 'deals'))->toBe([1, 1, 1, 1, 1]);
});

test('a history row of a deleted stage is ignored', function () {
    $deal = $this->team->deals['D'];
    $change = DealStageChange::query()->where('deal_id', $deal->id)->firstOrFail();
    $change->update(['to_pipeline_stage_id' => null]);

    $result = StageConversion::forPeriod(($this->thisMonth)(), $this->team->bob->id);

    expect($result['deals_created'])->toBe(2)
        ->and(array_column($result['rows'], 'deals'))->toBe([2, 2, 1, 0, 0]);
});

test('a reopened won deal counts at the open stages but not in the won row', function () {
    $result = StageConversion::forPeriod(($this->thisMonth)(), null);
    $reopened = $this->team->deals['F'];

    expect($reopened->refresh()->status)->toBe(DealStatus::Open)
        ->and($result['rows']['won']['deals'])->toBe(1);
});
