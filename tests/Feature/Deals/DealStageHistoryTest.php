<?php

use App\Enums\DealStatus;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use App\Services\Deals\PipelineStageOrder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-08 09:15:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

function stageHistoryStage(User $user, string $stage): int
{
    return pipelineStageNamed($user->personalOrganization()->id, $stage)->id;
}

function stageHistoryDeal(User $user, string $stage, string $title = 'Retainer'): Deal
{
    return Deal::factory()->inStage($stage)->create([
        'organization_id' => $user->personalOrganization()->id,
        'created_by' => $user->id,
        'title' => $title,
        'position' => '1000.0000000000',
    ]);
}

/**
 * @return array<int, array{from: ?int, to: ?int, by: ?int, at: string}>
 */
function stageHistoryRows(Deal $deal): array
{
    return DealStageChange::query()->where('deal_id', $deal->id)->orderBy('id')->get()
        ->map(fn (DealStageChange $change): array => [
            'from' => $change->from_pipeline_stage_id,
            'to' => $change->to_pipeline_stage_id,
            'by' => $change->changed_by,
            'at' => $change->changed_at->toDateTimeString(),
        ])->all();
}

test('creating a deal records its first stage', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Retainer', 'pipeline_stage_id' => stageHistoryStage($this->user, 'Lead')])
        ->call('create')
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'Retainer')->firstOrFail();

    expect(stageHistoryRows($deal))->toBe([
        ['from' => null, 'to' => stageHistoryStage($this->user, 'Lead'), 'by' => $this->user->id, 'at' => '2026-10-08 09:15:00'],
    ])->and($deal->stageChanges()->firstOrFail()->organization_id)->toBe($this->org->id);
});

test('a deal made without a logged-in user records no user', function () {
    auth()->logout();

    $deal = stageHistoryDeal($this->user, 'Discovery');

    expect(stageHistoryRows($deal))->toBe([
        ['from' => null, 'to' => stageHistoryStage($this->user, 'Discovery'), 'by' => null, 'at' => '2026-10-08 09:15:00'],
    ]);
});

test('moving a card on the board records one change', function () {
    $deal = stageHistoryDeal($this->user, 'Lead');
    Carbon::setTestNow('2026-10-09 10:00:00');

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $deal->id, (string) stageHistoryStage($this->user, 'Discovery'));

    expect(stageHistoryRows($deal))->toBe([
        ['from' => null, 'to' => stageHistoryStage($this->user, 'Lead'), 'by' => $this->user->id, 'at' => '2026-10-08 09:15:00'],
        ['from' => stageHistoryStage($this->user, 'Lead'), 'to' => stageHistoryStage($this->user, 'Discovery'), 'by' => $this->user->id, 'at' => '2026-10-09 10:00:00'],
    ]);
});

test('marking a deal as won from its page records the change', function () {
    $deal = stageHistoryDeal($this->user, 'Negotiating');

    Livewire::test(ViewDeal::class, ['record' => $deal->id])->callAction('moveToWon');

    $rows = stageHistoryRows($deal);

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['from'])->toBe(stageHistoryStage($this->user, 'Negotiating'))
        ->and($rows[1]['to'])->toBe(stageHistoryStage($this->user, 'Won'));
});

test('editing without changing the stage records nothing', function () {
    $deal = stageHistoryDeal($this->user, 'Lead');

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['title' => 'Retainer 2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deal->fresh()->title)->toBe('Retainer 2')
        ->and($deal->stageChanges()->count())->toBe(1);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['pipeline_stage_id' => stageHistoryStage($this->user, 'Proposal Sent')])
        ->call('save')
        ->assertHasNoFormErrors();

    $rows = stageHistoryRows($deal);

    expect($rows)->toHaveCount(2)
        ->and($rows[1]['from'])->toBe(stageHistoryStage($this->user, 'Lead'))
        ->and($rows[1]['to'])->toBe(stageHistoryStage($this->user, 'Proposal Sent'));
});

test('a failed board move leaves no history', function () {
    $deal = stageHistoryDeal($this->user, 'Negotiating');
    $armed = true;

    Deal::saving(function (Deal $saving) use (&$armed): void {
        if ($armed && $saving->status === DealStatus::Won) {
            throw new RuntimeException('Boom');
        }
    });

    try {
        expect(fn () => Livewire::test(DealPipeline::class)
            ->call('moveCard', (string) $deal->id, (string) stageHistoryStage($this->user, 'Won')))->toThrow(RuntimeException::class, 'Boom');
    } finally {
        $armed = false;
    }

    expect(stageHistoryRows($deal))->toBe([
        ['from' => null, 'to' => stageHistoryStage($this->user, 'Negotiating'), 'by' => $this->user->id, 'at' => '2026-10-08 09:15:00'],
    ]);
});

test('deleting an open stage records the move and keeps the history', function () {
    $deal = stageHistoryDeal($this->user, 'Discovery');
    $lead = stageHistoryStage($this->user, 'Lead');

    PipelineStageOrder::deleteOpenStage(DefaultPipeline::ensureFor($this->org), pipelineStageNamed($this->org->id, 'Discovery'), $lead);

    $rows = stageHistoryRows($deal);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['to'])->toBeNull()
        ->and($rows[1]['from'])->toBeNull()
        ->and($rows[1]['to'])->toBe($lead);
});

test('deleting a deal deletes its history', function () {
    $deal = stageHistoryDeal($this->user, 'Lead');
    DealStageMover::move($deal, pipelineStageNamed($this->org->id, 'Discovery'));

    expect($deal->stageChanges()->count())->toBe(2);

    $deal->delete();

    expect(DealStageChange::query()->withoutGlobalScope('organization')->where('deal_id', $deal->id)->count())->toBe(0);
});

test("another organization's history is invisible", function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $foreign = stageHistoryDeal($other, 'Lead', 'Globex deal');

    expect(DealStageChange::query()->withoutGlobalScope('organization')->where('deal_id', $foreign->id)->count())->toBe(1)
        ->and(DealStageChange::query()->count())->toBe(0);

    stageHistoryDeal($this->user, 'Lead');

    expect(DealStageChange::query()->count())->toBe(1);
});

test('the stage change of a deal always carries the organization of the deal', function () {
    $deal = stageHistoryDeal($this->user, 'Lead');

    $change = DealStageMover::recordStageChange($deal, null, now());

    expect($change->organization_id)->toBe($deal->organization_id);
});

test('the time in stage is shown on the deal panel', function (string $timezone, string $entered, string $now, string $text) {
    $this->org->forceFill(['timezone' => $timezone])->save();

    Carbon::setTestNow($entered);
    $deal = stageHistoryDeal($this->user, 'Lead');
    Carbon::setTestNow($now);

    Livewire::test(DealDetailsWidget::class, ['record' => $deal])
        ->assertSee('Time in stage')
        ->assertSee($text);
})->with([
    'three days' => ['UTC', '2026-10-08 09:15:00', '2026-10-11 13:15:00', '3 days (since Oct 8, 2026)'],
    'thirty minutes' => ['UTC', '2026-10-11 10:00:00', '2026-10-11 10:30:00', '30 minutes (since Oct 11, 2026)'],
    'local date' => ['Pacific/Auckland', '2026-10-17 20:00:00', '2026-10-20 12:00:00', '2 days (since Oct 18, 2026)'],
]);

test('the time in stage is hidden when the history does not know', function () {
    Event::fakeFor(fn () => $deal = stageHistoryDeal($this->user, 'Lead'));
    $deal = Deal::query()->where('title', 'Retainer')->firstOrFail();

    expect($deal->stageChanges()->count())->toBe(0);

    Livewire::test(DealDetailsWidget::class, ['record' => $deal])->assertDontSee('Time in stage');
});

test('the time in stage is hidden when the latest row is for another stage', function () {
    $deal = stageHistoryDeal($this->user, 'Lead');

    DB::table('deals')->where('id', $deal->id)->update(['pipeline_stage_id' => stageHistoryStage($this->user, 'Discovery')]);

    Livewire::test(DealDetailsWidget::class, ['record' => $deal->fresh()])->assertDontSee('Time in stage');
});

test('rows written in the same second are ordered by id', function () {
    $deal = stageHistoryDeal($this->user, 'Lead');
    DealStageMover::move($deal, pipelineStageNamed($this->org->id, 'Discovery'));

    expect($deal->stageChanges()->count())->toBe(2)
        ->and($deal->fresh()->stageEnteredAt()?->toDateTimeString())->toBe('2026-10-08 09:15:00');
});

test('the time in stage starts at the latest move, not the first', function () {
    $deal = stageHistoryDeal($this->user, 'Lead');
    Carbon::setTestNow('2026-10-09 10:00:00');
    DealStageMover::move($deal, pipelineStageNamed($this->org->id, 'Discovery'));

    expect($deal->fresh()->stageEnteredAt()?->toDateTimeString())->toBe('2026-10-09 10:00:00');
});

test('the time in stage matches when the in-memory stage id is a numeric string', function () {
    $deal = stageHistoryDeal($this->user, 'Lead');
    Carbon::setTestNow('2026-10-09 10:00:00');

    $deal->update(['pipeline_stage_id' => (string) stageHistoryStage($this->user, 'Discovery')]);

    expect($deal->stageEnteredAt()?->toDateTimeString())->toBe('2026-10-09 10:00:00');
});
