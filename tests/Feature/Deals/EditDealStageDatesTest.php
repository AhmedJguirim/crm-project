<?php

use App\Enums\DealStatus;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 14:30:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

function editDealStageDatesDeal(User $user, string $stage, DealStatus $status, ?string $wonAt, ?string $lostAt, string $title = 'Retainer'): Deal
{
    return Deal::factory()->inStage($stage)->create([
        'organization_id' => $user->personalOrganization()->id,
        'created_by' => $user->id,
        'title' => $title,
        'status' => $status,
        'won_at' => $wonAt,
        'lost_at' => $lostAt,
        'notes' => null,
    ]);
}

test('a won deal edited without changing the stage keeps its won date', function () {
    $deal = editDealStageDatesDeal($this->user, 'Won', DealStatus::Won, '2026-08-28 14:30:00', null);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['notes' => 'typo fixed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $deal->refresh();

    expect($deal->notes)->toBe('typo fixed')
        ->and($deal->pipelineStage->name)->toBe('Won')
        ->and($deal->status)->toBe(DealStatus::Won)
        ->and($deal->won_at?->toDateTimeString())->toBe('2026-08-28 14:30:00')
        ->and($deal->lost_at)->toBeNull();
});

test('a lost deal edited without changing the stage keeps its lost date', function () {
    $deal = editDealStageDatesDeal($this->user, 'Lost', DealStatus::Lost, null, '2026-09-01 09:00:00');

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['notes' => 'typo fixed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $deal->refresh();

    expect($deal->lost_at?->toDateTimeString())->toBe('2026-09-01 09:00:00')
        ->and($deal->won_at)->toBeNull()
        ->and($deal->status)->toBe(DealStatus::Lost);
});

test('changing the stage sets, moves or clears the closing dates', function (
    string $from,
    ?string $wonBefore,
    ?string $lostBefore,
    string $to,
    DealStatus $status,
    ?string $wonAfter,
    ?string $lostAfter,
) {
    $deal = editDealStageDatesDeal($this->user, $from, match ($from) {
        'Won' => DealStatus::Won,
        'Lost' => DealStatus::Lost,
        default => DealStatus::Open,
    }, $wonBefore, $lostBefore);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['pipeline_stage_id' => pipelineStageNamed($deal->organization_id, $to)->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $deal->refresh();

    expect($deal->pipelineStage->name)->toBe($to)
        ->and($deal->status)->toBe($status)
        ->and($deal->won_at?->toDateTimeString())->toBe($wonAfter)
        ->and($deal->lost_at?->toDateTimeString())->toBe($lostAfter);
})->with([
    'negotiating to won' => ['Negotiating', null, null, 'Won', DealStatus::Won, '2026-10-07 14:30:00', null],
    'negotiating to lost' => ['Negotiating', null, null, 'Lost', DealStatus::Lost, null, '2026-10-07 14:30:00'],
    'won to lost' => ['Won', '2026-08-28 14:30:00', null, 'Lost', DealStatus::Lost, null, '2026-10-07 14:30:00'],
    'lost to won' => ['Lost', null, '2026-09-01 09:00:00', 'Won', DealStatus::Won, '2026-10-07 14:30:00', null],
    'won to negotiating' => ['Won', '2026-08-28 14:30:00', null, 'Negotiating', DealStatus::Open, null, null],
    'lost to lead' => ['Lost', null, '2026-09-01 09:00:00', 'Lead', DealStatus::Open, null, null],
]);

test('an old won deal with no won date gets one on the next save', function () {
    $deal = editDealStageDatesDeal($this->user, 'Won', DealStatus::Won, null, null);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['notes' => 'x'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deal->refresh()->won_at?->toDateTimeString())->toBe('2026-10-07 14:30:00');
});
