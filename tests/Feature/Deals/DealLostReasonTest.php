<?php

use App\Enums\DealStatus;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\LostReason;
use App\Models\Organization;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->reason = fn (string $name): LostReason => LostReason::factory()->for($this->org)->create(['name' => $name]);
    $this->priceTooHigh = ($this->reason)('Price too high');
    $this->noBudget = ($this->reason)('No budget');
    $this->badTiming = ($this->reason)('Bad timing');
    $this->noResponse = ($this->reason)('No response');
    $this->noResponse->delete();

    $this->globex = Organization::factory()->create();
    $this->globexReason = LostReason::factory()->for($this->globex)->create(['name' => 'Globex reason']);

    $this->openDeal = fn (string $title = 'Hosting Renewal'): Deal => Deal::factory()->inStage('Negotiating')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => $title,
        'notes' => null,
    ]);
    $this->lostDeal = fn (?LostReason $reason = null, ?string $note = null, string $title = 'Lost Deal'): Deal => Deal::factory()->inStage('Lost')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => $title,
        'status' => DealStatus::Lost,
        'lost_at' => '2026-10-20 08:00:00',
        'lost_reason_id' => $reason?->id,
        'lost_reason_note' => $note,
        'notes' => null,
    ]);
});

test('move to lost with a reason and a note stores both, trimmed, in one history row', function () {
    $deal = ($this->openDeal)();
    $before = DealStageChange::query()->where('deal_id', $deal->id)->count();

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('moveToLost', ['lost_reason_id' => $this->priceTooHigh->id, 'lost_reason_note' => '  Chose a cheaper vendor.  '])
        ->assertHasNoActionErrors()
        ->assertNotified('Deal moved to Lost');

    $deal->refresh();

    expect($deal->pipelineStage->name)->toBe('Lost')
        ->and($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_at?->toDateTimeString())->toBe('2026-10-21 09:00:00')
        ->and($deal->lost_reason_id)->toBe($this->priceTooHigh->id)
        ->and($deal->lost_reason_note)->toBe('Chose a cheaper vendor.')
        ->and(DealStageChange::query()->where('deal_id', $deal->id)->count())->toBe($before + 1);
});

test('move to lost without a reason leaves both empty, and a blank note is stored as null', function () {
    $deal = ($this->openDeal)();
    $other = ($this->openDeal)('Other');

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('moveToLost')
        ->assertHasNoActionErrors();
    Livewire::test(ViewDeal::class, ['record' => $other->id])
        ->callAction('moveToLost', ['lost_reason_note' => "   \n "])
        ->assertHasNoActionErrors();

    expect($deal->refresh()->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_reason_id)->toBeNull()
        ->and($deal->lost_reason_note)->toBeNull()
        ->and($other->refresh()->status)->toBe(DealStatus::Lost)
        ->and($other->lost_reason_note)->toBeNull();
});

test('move to lost refuses a reason that is not offered', function (string $which) {
    $deal = ($this->openDeal)();
    $value = match ($which) {
        'deleted' => $this->noResponse->id,
        'other organization' => $this->globexReason->id,
        'unknown' => 999999,
        'not an id' => 'abc',
    };

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('moveToLost', ['lost_reason_id' => $value])
        ->assertHasActionErrors(['lost_reason_id']);

    $deal->refresh();

    expect($deal->status)->toBe(DealStatus::Open)
        ->and($deal->pipelineStage->name)->toBe('Negotiating')
        ->and($deal->lost_reason_id)->toBeNull();
})->with(['deleted', 'other organization', 'unknown', 'not an id']);

test('the note is limited to 1000 characters', function () {
    $deal = ($this->openDeal)();
    $other = ($this->openDeal)('Other');

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('moveToLost', ['lost_reason_note' => str_repeat('a', 1000)])
        ->assertHasNoActionErrors();
    Livewire::test(ViewDeal::class, ['record' => $other->id])
        ->callAction('moveToLost', ['lost_reason_note' => str_repeat('a', 1001)])
        ->assertHasActionErrors(['lost_reason_note']);

    expect(mb_strlen($deal->refresh()->lost_reason_note))->toBe(1000)
        ->and($other->refresh()->status)->toBe(DealStatus::Open);
});

test('the offered reasons are the live ones by name, plus the deleted reason the deal already has', function () {
    expect(DealForm::lostReasonOptions(null))->toBe([
        $this->badTiming->id => 'Bad timing',
        $this->noBudget->id => 'No budget',
        $this->priceTooHigh->id => 'Price too high',
    ]);

    $deal = ($this->lostDeal)($this->noResponse);

    expect(DealForm::lostReasonOptions($deal))->toBe([
        $this->badTiming->id => 'Bad timing',
        $this->noBudget->id => 'No budget',
        $this->priceTooHigh->id => 'Price too high',
        $this->noResponse->id => 'No response (deleted)',
    ])
        ->and(DealForm::lostReasonOptions(($this->lostDeal)(null, null, 'No reason')))->not->toHaveKey($this->noResponse->id);
});

test('leaving Lost clears the reason and the note', function (string $path) {
    $deal = ($this->lostDeal)($this->priceTooHigh, 'Too expensive.');

    match ($path) {
        'edit form' => Livewire::test(EditDeal::class, ['record' => $deal->id])
            ->fillForm(['pipeline_stage_id' => pipelineStageNamed($this->org, 'Negotiating')->id])
            ->call('save')
            ->assertHasNoFormErrors(),
        'board' => Livewire::test(DealPipeline::class)
            ->call('moveCard', (string) $deal->id, (string) pipelineStageNamed($this->org, 'Lead')->id),
        'mover to won' => DealStageMover::move($deal, pipelineStageNamed($this->org, 'Won')),
    };

    $deal->refresh();

    expect($deal->status)->not->toBe(DealStatus::Lost)
        ->and($deal->lost_reason_id)->toBeNull()
        ->and($deal->lost_reason_note)->toBeNull();
})->with(['edit form', 'board', 'mover to won']);

test('losing a reopened deal again does not bring the old reason back', function () {
    $deal = ($this->lostDeal)($this->priceTooHigh, 'Too expensive.');
    DealStageMover::move($deal, pipelineStageNamed($this->org, 'Negotiating'));

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('moveToLost')
        ->assertHasNoActionErrors();

    $deal->refresh();

    expect($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_reason_id)->toBeNull()
        ->and($deal->lost_reason_note)->toBeNull();
});

test('the deal form shows the reason fields only while the Lost stage is picked', function () {
    $deal = ($this->openDeal)();
    $lostStage = pipelineStageNamed($this->org, 'Lost');

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->assertFormFieldHidden('lost_reason_id')
        ->assertFormFieldHidden('lost_reason_note')
        ->fillForm(['pipeline_stage_id' => $lostStage->id])
        ->assertFormFieldVisible('lost_reason_id')
        ->assertFormFieldVisible('lost_reason_note')
        ->fillForm(['lost_reason_id' => $this->badTiming->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $deal->refresh();

    expect($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_reason_id)->toBe($this->badTiming->id);
});

test('the Lost stage is recognised whatever the type of the stage value', function () {
    $lostId = pipelineStageNamed($this->org, 'Lost')->id;

    expect(DealForm::isLostStage($lostId))->toBeTrue()
        ->and(DealForm::isLostStage((string) $lostId))->toBeTrue()
        ->and(DealForm::isLostStage(pipelineStageNamed($this->org, 'Won')->id))->toBeFalse()
        ->and(DealForm::isLostStage(null))->toBeFalse()
        ->and(DealForm::isLostStage('abc'))->toBeFalse()
        ->and(DealForm::isLostStage([$lostId]))->toBeFalse();
});

test('a deal can be created straight in Lost with a reason and a note', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm([
            'title' => 'Lost On Arrival',
            'pipeline_stage_id' => pipelineStageNamed($this->org, 'Lost')->id,
            'lost_reason_id' => $this->noBudget->id,
            'lost_reason_note' => 'Frozen budget.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'Lost On Arrival')->firstOrFail();

    expect($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_reason_id)->toBe($this->noBudget->id)
        ->and($deal->lost_reason_note)->toBe('Frozen budget.');
});

test('a reason forged on an open stage is not saved', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm([
            'title' => 'Open Deal',
            'pipeline_stage_id' => pipelineStageNamed($this->org, 'Lead')->id,
            'lost_reason_id' => $this->noBudget->id,
            'lost_reason_note' => 'x',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'Open Deal')->firstOrFail();

    expect($deal->status)->toBe(DealStatus::Open)
        ->and($deal->lost_reason_id)->toBeNull()
        ->and($deal->lost_reason_note)->toBeNull();
});

test('a forged reason of another organization is refused by the deal form', function () {
    $deal = ($this->lostDeal)();

    $page = Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['lost_reason_id' => $this->globexReason->id])
        ->call('save')
        ->assertHasFormErrors(['lost_reason_id']);

    expect($page->errors()->get('data.lost_reason_id'))->toBe(['The selected lost reason is invalid.'])
        ->and($deal->refresh()->lost_reason_id)->toBeNull();
});

test('editing a lost deal keeps a reason that was deleted since', function () {
    $deal = ($this->lostDeal)($this->priceTooHigh, 'Too expensive.');
    $this->priceTooHigh->delete();

    Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['title' => 'Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();

    $deal->refresh();

    expect($deal->title)->toBe('Renamed')
        ->and($deal->lost_reason_id)->toBe($this->priceTooHigh->id)
        ->and($deal->lost_reason_note)->toBe('Too expensive.');
});

test('the observer refuses another organization reason on a lost deal, on update and on create', function () {
    $deal = ($this->lostDeal)($this->priceTooHigh);

    expect(fn () => $deal->update(['lost_reason_id' => $this->globexReason->id]))->toThrow(ModelNotFoundException::class)
        ->and($deal->fresh()->lost_reason_id)->toBe($this->priceTooHigh->id)
        ->and(fn () => ($this->lostDeal)($this->globexReason, null, 'Created'))->toThrow(ModelNotFoundException::class)
        ->and(Deal::query()->where('title', 'Created')->exists())->toBeFalse();
});

test('the observer clears a reason set on a deal that is not lost', function () {
    $deal = ($this->openDeal)();
    $deal->update(['lost_reason_id' => $this->globexReason->id, 'lost_reason_note' => 'forged']);

    expect($deal->fresh()->lost_reason_id)->toBeNull()
        ->and($deal->fresh()->lost_reason_note)->toBeNull();
});

test('the deal page shows the lost reason and note', function () {
    $withReason = ($this->lostDeal)($this->priceTooHigh, "Line one\nLine two");
    $withoutReason = ($this->lostDeal)(null, null, 'No Reason Deal');
    $withDeleted = ($this->lostDeal)($this->noBudget, null, 'Deleted Reason Deal');
    $this->noBudget->delete();

    Livewire::test(DealDetailsWidget::class, ['record' => $withReason])
        ->assertSee('Lost Reason')
        ->assertSee('Price too high')
        ->assertSee('Lost Note')
        ->assertSee('Line one');
    Livewire::test(DealDetailsWidget::class, ['record' => $withoutReason])
        ->assertSee('Lost Reason')
        ->assertSee('No reason given')
        ->assertDontSee('Lost Note');
    Livewire::test(DealDetailsWidget::class, ['record' => $withDeleted])
        ->assertSee('No budget (deleted)');
    Livewire::test(DealDetailsWidget::class, ['record' => ($this->openDeal)()])
        ->assertDontSee('Lost Reason')
        ->assertDontSee('No reason given');
});
