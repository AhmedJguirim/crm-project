<?php

use App\Enums\DealStage;
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

function editDealStageDatesDeal(User $user, DealStage $stage, DealStatus $status, ?string $wonAt, ?string $lostAt, string $title = 'Retainer'): Deal
{
    return Deal::factory()->create([
        'organization_id' => $user->personalOrganization()->id,
        'created_by' => $user->id,
        'title' => $title,
        'stage' => $stage,
        'status' => $status,
        'won_at' => $wonAt,
        'lost_at' => $lostAt,
        'notes' => null,
    ]);
}

test('a won deal edited without changing the stage keeps its won date', function () {
    $deal = editDealStageDatesDeal($this->user, DealStage::Won, DealStatus::Won, '2026-08-28 14:30:00', null);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['notes' => 'typo fixed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $deal->refresh();

    expect($deal->notes)->toBe('typo fixed')
        ->and($deal->stage)->toBe(DealStage::Won)
        ->and($deal->status)->toBe(DealStatus::Won)
        ->and($deal->won_at?->toDateTimeString())->toBe('2026-08-28 14:30:00')
        ->and($deal->lost_at)->toBeNull();
});

test('a lost deal edited without changing the stage keeps its lost date', function () {
    $deal = editDealStageDatesDeal($this->user, DealStage::Lost, DealStatus::Lost, null, '2026-09-01 09:00:00');

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
    DealStage $from,
    ?string $wonBefore,
    ?string $lostBefore,
    DealStage $to,
    DealStatus $status,
    ?string $wonAfter,
    ?string $lostAfter,
) {
    $deal = editDealStageDatesDeal($this->user, $from, match ($from) {
        DealStage::Won => DealStatus::Won,
        DealStage::Lost => DealStatus::Lost,
        default => DealStatus::Open,
    }, $wonBefore, $lostBefore);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['stage' => $to])
        ->call('save')
        ->assertHasNoFormErrors();

    $deal->refresh();

    expect($deal->stage)->toBe($to)
        ->and($deal->status)->toBe($status)
        ->and($deal->won_at?->toDateTimeString())->toBe($wonAfter)
        ->and($deal->lost_at?->toDateTimeString())->toBe($lostAfter);
})->with([
    'negotiating to won' => [DealStage::Negotiating, null, null, DealStage::Won, DealStatus::Won, '2026-10-07 14:30:00', null],
    'negotiating to lost' => [DealStage::Negotiating, null, null, DealStage::Lost, DealStatus::Lost, null, '2026-10-07 14:30:00'],
    'won to lost' => [DealStage::Won, '2026-08-28 14:30:00', null, DealStage::Lost, DealStatus::Lost, null, '2026-10-07 14:30:00'],
    'lost to won' => [DealStage::Lost, null, '2026-09-01 09:00:00', DealStage::Won, DealStatus::Won, '2026-10-07 14:30:00', null],
    'won to negotiating' => [DealStage::Won, '2026-08-28 14:30:00', null, DealStage::Negotiating, DealStatus::Open, null, null],
    'lost to lead' => [DealStage::Lost, null, '2026-09-01 09:00:00', DealStage::Lead, DealStatus::Open, null, null],
]);

test('an old won deal with no won date gets one on the next save', function () {
    $deal = editDealStageDatesDeal($this->user, DealStage::Won, DealStatus::Won, null, null);

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['notes' => 'x'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($deal->refresh()->won_at?->toDateTimeString())->toBe('2026-10-07 14:30:00');
});
