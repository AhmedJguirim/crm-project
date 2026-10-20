<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-06 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->deal = timeInStageDeal($this->user, 'Lead', 'Retainer');

    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->timeInStageEntry = function (Deal $deal): ?TextEntry {
        $board = Livewire::test(DealPipeline::class)->instance()->getBoard();

        return collect($board->getCardSchema($deal)->getFlatComponents(withHidden: true))
            ->first(fn ($component): bool => $component instanceof TextEntry && $component->getName() === 'time_in_stage');
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

function timeInStageDeal(User $user, string $stage, string $title, string $position = '1000.0000000000'): Deal
{
    return Deal::factory()->inStage($stage)->create([
        'organization_id' => $user->personalOrganization()->id,
        'created_by' => $user->id,
        'title' => $title,
        'position' => $position,
    ]);
}

test('the card shows the time in stage', function () {
    Livewire::test(DealPipeline::class)->assertSee('3 days in stage');

    $entry = ($this->timeInStageEntry)($this->deal);

    expect($entry)->not->toBeNull()
        ->and($entry->getState())->toBe('3 days in stage')
        ->and($entry->isVisible())->toBeTrue();
});

test('the table shows the time in stage', function () {
    Livewire::test(ListDeals::class)
        ->assertTableColumnExists('time_in_stage')
        ->assertTableColumnFormattedStateSet('time_in_stage', '3 days', $this->deal);

    $column = Livewire::test(ListDeals::class)->instance()->getTable()->getColumn('time_in_stage');

    expect($column->getLabel())->toBe('Time in stage')
        ->and($column->isSortable())->toBeFalse()
        ->and($column->isSearchable())->toBeFalse()
        ->and($column->isToggleable())->toBeFalse();
});

test('the time in stage starts at the latest move', function () {
    Carbon::setTestNow('2026-10-09 10:00:00');
    DealStageMover::move($this->deal, pipelineStageNamed($this->org->id, 'Discovery'));
    Carbon::setTestNow('2026-10-09 12:00:00');

    $deal = $this->deal->fresh();

    Livewire::test(DealPipeline::class)->assertSee('2 hours in stage');
    Livewire::test(ListDeals::class)->assertTableColumnFormattedStateSet('time_in_stage', '2 hours', $deal);
});

test('unknown history is hidden on the card and null in the table', function () {
    Event::fakeFor(fn () => timeInStageDeal($this->user, 'Lead', 'Ghost', '2000.0000000000'));
    $ghost = Deal::query()->where('title', 'Ghost')->firstOrFail();

    $moved = timeInStageDeal($this->user, 'Lead', 'Moved quietly', '3000.0000000000');
    DB::table('deals')->where('id', $moved->id)->update(['pipeline_stage_id' => pipelineStageNamed($this->org->id, 'Discovery')->id]);
    $moved = $moved->fresh();

    expect($ghost->stageChanges()->count())->toBe(0);

    foreach ([$ghost, $moved] as $deal) {
        $entry = ($this->timeInStageEntry)($deal);

        expect($entry->isVisible())->toBeFalse()
            ->and($entry->getState())->toBeNull();
    }

    Livewire::test(ListDeals::class)
        ->assertTableColumnStateSet('time_in_stage', null, $ghost)
        ->assertTableColumnStateSet('time_in_stage', null, $moved)
        ->assertTableColumnStateSet('time_in_stage', '3 days', $this->deal);
});

test('rows written in the same second are ordered by id', function (string $currentStage, string $earlierStage) {
    $current = pipelineStageNamed($this->org->id, $currentStage);
    $earlier = pipelineStageNamed($this->org->id, $earlierStage);
    Event::fakeFor(fn () => Deal::factory()->inStage($currentStage)->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Tie',
        'position' => '2000.0000000000',
    ]));
    $deal = Deal::query()->where('title', 'Tie')->firstOrFail();

    DealStageChange::factory()->create(['deal_id' => $deal->id, 'from_pipeline_stage_id' => null, 'to_pipeline_stage_id' => $earlier->id, 'changed_at' => '2026-10-08 11:00:00']);
    DealStageChange::factory()->create(['deal_id' => $deal->id, 'from_pipeline_stage_id' => $earlier->id, 'to_pipeline_stage_id' => $current->id, 'changed_at' => '2026-10-08 11:00:00']);

    Livewire::test(ListDeals::class)->assertTableColumnFormattedStateSet('time_in_stage', '1 day', $deal);
})->with([
    'moved forward' => ['Discovery', 'Lead'],
    'moved back' => ['Lead', 'Discovery'],
]);

test('the time in stage of one instance follows a later move when the history is not preloaded', function () {
    $deal = Deal::query()->findOrFail($this->deal->id);

    expect($deal->timeInStageForHumans())->toBe('3 days');

    Carbon::setTestNow('2026-10-09 10:00:00');
    DealStageMover::move($deal, pipelineStageNamed($this->org->id, 'Discovery'));
    Carbon::setTestNow('2026-10-09 12:00:00');

    expect($deal->timeInStageForHumans())->toBe('2 hours');
});

test('won and lost deals show it too', function () {
    Carbon::setTestNow('2026-10-08 11:00:00');
    $won = timeInStageDeal($this->user, 'Lead', 'Closed', '2000.0000000000');
    DealStageMover::move($won, pipelineStageNamed($this->org->id, 'Won'));
    $lost = timeInStageDeal($this->user, 'Lead', 'Dropped', '3000.0000000000');
    DealStageMover::move($lost, pipelineStageNamed($this->org->id, 'Lost'));
    Carbon::setTestNow('2026-10-09 12:00:00');

    expect($won->fresh()->status)->toBe(DealStatus::Won)
        ->and($lost->fresh()->status)->toBe(DealStatus::Lost);

    foreach ([$won->fresh(), $lost->fresh()] as $deal) {
        $entry = ($this->timeInStageEntry)($deal);

        expect($entry->getState())->toBe('1 day in stage');
    }

    Livewire::test(DealPipeline::class)->assertSee('1 day in stage');
});

test("another organization's history row is never used", function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    DB::table('deal_stage_changes')->insert([
        'organization_id' => $other->personalOrganization()->id,
        'deal_id' => $this->deal->id,
        'from_pipeline_stage_id' => null,
        'to_pipeline_stage_id' => $this->deal->pipeline_stage_id,
        'changed_by' => null,
        'changed_at' => '2026-10-09 11:59:30',
    ]);

    Livewire::test(ListDeals::class)->assertTableColumnFormattedStateSet('time_in_stage', '3 days', $this->deal);
    Livewire::test(DealPipeline::class)->assertSee('3 days in stage')->assertDontSee('30 seconds in stage');
});

test('more deals do not add history queries', function (string $page) {
    $countHistoryQueries = function () use ($page): int {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            if (str_contains($query->sql, '"deal_stage_changes"')) {
                $count++;
            }
        });

        Livewire::test($page);

        return $count;
    };

    $one = $countHistoryQueries();

    foreach (range(1, 6) as $i) {
        timeInStageDeal($this->user, 'Lead', "Extra {$i}", (1000 + $i * 10).'.0000000000');
    }

    expect($countHistoryQueries())->toBe($one)->toBeGreaterThan(0);
})->with([
    'board' => DealPipeline::class,
    'table' => ListDeals::class,
]);

test('every role sees the time in stage', function (OrganizationRole $role) {
    $member = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);
    Filament::setTenant($this->org);

    Livewire::test(DealPipeline::class)->assertSee('3 days in stage');
    Livewire::test(ListDeals::class)->assertTableColumnFormattedStateSet('time_in_stage', '3 days', $this->deal);
})->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);
