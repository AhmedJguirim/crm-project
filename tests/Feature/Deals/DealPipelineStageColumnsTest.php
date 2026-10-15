<?php

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Models\Deal;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-08 09:15:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->stage = fn (DealStage $stage) => DefaultPipeline::stageFor($this->org->id, $stage);
    $this->deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
        'stage' => DealStage::Lead,
        'status' => DealStatus::Open,
        'value' => 5000,
        'position' => '1000.0000000000',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the board has one column per stage of the pipeline, by position, named and colored like the stage', function () {
    ($this->stage)(DealStage::Discovery)->update(['name' => 'Qualified', 'color' => 'success']);

    $columns = collect(Livewire::test(DealPipeline::class)->instance()->getBoard()->getColumns());

    expect($columns->map(fn ($column): string => $column->getLabel())->all())->toBe(['Lead', 'Qualified', 'Proposal Sent', 'Negotiating', 'Won', 'Lost'])
        ->and($columns->map(fn ($column): string => $column->getName())->all())->toBe(DefaultPipeline::stagesOf($this->org->id)->map(fn ($stage): string => (string) $stage->id)->all())
        ->and($columns->get(1)->getColor())->toBe('success');
});

test('dragging a card to the Won column moves it to the Won stage', function () {
    Livewire::test(DealPipeline::class)->call('moveCard', (string) $this->deal->id, (string) ($this->stage)(DealStage::Won)->id);

    $deal = $this->deal->fresh();

    expect($deal->pipeline_stage_id)->toBe(($this->stage)(DealStage::Won)->id)
        ->and($deal->stage)->toBe(DealStage::Won)
        ->and($deal->status)->toBe(DealStatus::Won)
        ->and($deal->won_at->toDateTimeString())->toBe('2026-10-08 09:15:00');
});

test('dragging to a renamed stage still moves the card and sets the status of its type', function () {
    ($this->stage)(DealStage::Lost)->update(['name' => 'Closed lost']);

    Livewire::test(DealPipeline::class)->call('moveCard', (string) $this->deal->id, (string) ($this->stage)(DealStage::Lost)->id);

    expect($this->deal->fresh()->status)->toBe(DealStatus::Lost)
        ->and($this->deal->fresh()->lost_at->toDateTimeString())->toBe('2026-10-08 09:15:00');
});

test('move stage announces the new name of a renamed stage', function () {
    ($this->stage)(DealStage::Negotiating)->update(['name' => 'Final talks']);

    Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $this->deal->id])
        ->call('mountAction', 'moveStage', [], ['recordKey' => (string) $this->deal->id])
        ->fillForm(['pipeline_stage_id' => ($this->stage)(DealStage::Negotiating)->id])
        ->call('callMountedAction')
        ->assertNotified('Deal moved to Final talks');

    expect($this->deal->fresh()->pipeline_stage_id)->toBe(($this->stage)(DealStage::Negotiating)->id);
});

test('move stage refuses the stage id of another organization', function () {
    $foreign = DefaultPipeline::stageFor(User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization()->id, DealStage::Won);

    Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $this->deal->id])
        ->call('mountAction', 'moveStage', [], ['recordKey' => (string) $this->deal->id])
        ->fillForm(['pipeline_stage_id' => $foreign->id])
        ->call('callMountedAction')
        ->assertHasFormErrors(['pipeline_stage_id']);

    expect($this->deal->fresh()->pipeline_stage_id)->toBe(($this->stage)(DealStage::Lead)->id)
        ->and($this->deal->fresh()->status)->toBe(DealStatus::Open);
});

test('dragging to a column of another organization is ignored quietly, before any write', function () {
    $foreign = DefaultPipeline::stageFor(User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization()->id, DealStage::Won);
    $before = $this->deal->fresh()->getAttributes();

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $this->deal->id, (string) $foreign->id)
        ->assertSuccessful();

    expect($this->deal->fresh()->getAttributes())->toBe($before);
});
