<?php

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->acme = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->acme);
});

/**
 * @return PipelineStage the stage a deal references, read without the tenant scope
 */
function dealPipelineStageOf(Deal $deal): PipelineStage
{
    return PipelineStage::query()->withoutGlobalScope('organization')->findOrFail($deal->fresh()->pipeline_stage_id);
}

test('a deal created with a stage gets the pipeline stage at the same position', function (DealStage $stage, string $name, int $position, DealStatus $type) {
    $deal = Deal::factory()->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id, 'stage' => $stage]);

    $pipelineStage = dealPipelineStageOf($deal);
    $acmePipeline = Pipeline::forOrganization($this->acme->id)->oldest('id')->firstOrFail();

    expect($pipelineStage->pipeline_id)->toBe($acmePipeline->id)
        ->and($pipelineStage->organization_id)->toBe($this->acme->id)
        ->and($pipelineStage->name)->toBe($name)
        ->and($pipelineStage->position)->toBe($position)
        ->and($pipelineStage->type)->toBe($type);
})->with([
    'lead' => [DealStage::Lead, 'Lead', 1, DealStatus::Open],
    'discovery' => [DealStage::Discovery, 'Discovery', 2, DealStatus::Open],
    'proposal sent' => [DealStage::ProposalSent, 'Proposal Sent', 3, DealStatus::Open],
    'negotiating' => [DealStage::Negotiating, 'Negotiating', 4, DealStatus::Open],
    'won' => [DealStage::Won, 'Won', 5, DealStatus::Won],
    'lost' => [DealStage::Lost, 'Lost', 6, DealStatus::Lost],
]);

test('moving a deal moves its pipeline stage', function () {
    $deal = Deal::factory()->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id, 'stage' => DealStage::Lead]);

    DealStageMover::move($deal, DefaultPipeline::stageFor($this->acme->id, DealStage::Won));

    $pipelineStage = dealPipelineStageOf($deal);

    expect($pipelineStage->name)->toBe('Won')
        ->and($pipelineStage->type)->toBe(DealStatus::Won)
        ->and($pipelineStage->probability)->toBe(100);
});

test('a save that does not touch the stage keeps the pipeline stage', function () {
    $deal = Deal::factory()->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id, 'stage' => DealStage::Discovery]);
    $lostStage = PipelineStage::forOrganization($this->acme->id)->where('position', 6)->firstOrFail();

    $deal->forceFill(['pipeline_stage_id' => $lostStage->id])->saveQuietly();
    $deal->update(['title' => 'Renamed']);

    expect($deal->fresh()->pipeline_stage_id)->toBe($lostStage->id)
        ->and($deal->fresh()->title)->toBe('Renamed');
});

test('a deal created from the deal form gets its pipeline stage', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Form deal', 'pipeline_stage_id' => DefaultPipeline::stageFor($this->acme->id, DealStage::ProposalSent)->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'Form deal')->firstOrFail();

    expect(dealPipelineStageOf($deal)->name)->toBe('Proposal Sent')
        ->and(dealPipelineStageOf($deal)->organization_id)->toBe($this->acme->id);
});

test('a deal of another organization gets that organization stage, even under another tenant', function () {
    $globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $globexPipeline = Pipeline::forOrganization($globex->id)->oldest('id')->firstOrFail();

    $deal = Deal::factory()->create(['organization_id' => $globex->id, 'stage' => DealStage::Negotiating]);

    $pipelineStage = dealPipelineStageOf($deal);

    expect($pipelineStage->pipeline_id)->toBe($globexPipeline->id)
        ->and($pipelineStage->organization_id)->toBe($globex->id)
        ->and($pipelineStage->name)->toBe('Negotiating');
});

test('a deal given the pipeline stage of another organization is refused on creation and on update', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreignLead = DefaultPipeline::stageFor($other->id, DealStage::Lead);
    $deal = Deal::factory()->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id, 'stage' => DealStage::Discovery]);

    expect(fn () => Deal::factory()->create([
        'organization_id' => $this->acme->id,
        'created_by' => $this->owner->id,
        'pipeline_stage_id' => $foreignLead->id,
    ]))->toThrow(ModelNotFoundException::class);

    expect(fn () => $deal->update(['pipeline_stage_id' => $foreignLead->id]))->toThrow(ModelNotFoundException::class)
        ->and($deal->fresh()->stage)->toBe(DealStage::Discovery)
        ->and(dealPipelineStageOf($deal)->name)->toBe('Discovery');
});

test('the legacy stage mirrors the pipeline stage on creation and on a move', function (DealStage $stage) {
    $pipelineStage = DefaultPipeline::stageFor($this->acme->id, $stage);
    $deal = Deal::query()->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id, 'title' => 'Mirrored', 'status' => DealStatus::Open, 'pipeline_stage_id' => $pipelineStage->id]);

    expect($deal->fresh()->stage)->toBe($stage);

    $deal->update(['pipeline_stage_id' => DefaultPipeline::stageFor($this->acme->id, DealStage::Lead)->id]);

    expect($deal->fresh()->stage)->toBe(DealStage::Lead);
})->with(DealStage::cases());

test('a direct write of the legacy stage is reverted by the mirror', function () {
    $deal = Deal::factory()->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id, 'stage' => DealStage::Lead]);
    $before = $deal->fresh();

    $deal->update(['stage' => DealStage::Won]);

    $after = $deal->fresh();

    expect($after->stage)->toBe(DealStage::Lead)
        ->and($after->pipeline_stage_id)->toBe($before->pipeline_stage_id)
        ->and($after->status)->toBe($before->status);
});

test('a deal created without a pipeline stage is refused by the database', function () {
    expect(fn () => DB::transaction(fn () => Deal::query()->create([
        'organization_id' => $this->acme->id,
        'created_by' => $this->owner->id,
        'title' => 'No stage',
        'status' => DealStatus::Open,
        'stage' => DealStage::Lead,
    ])))->toThrow(QueryException::class, 'pipeline_stage_id');
});

test('legacyStageFor maps the six positions and refuses another', function () {
    foreach (DealStage::cases() as $index => $stage) {
        expect(DefaultPipeline::legacyStageFor(new PipelineStage(['position' => $index + 1])))->toBe($stage);
    }

    expect(fn () => DefaultPipeline::legacyStageFor(new PipelineStage(['position' => 7])))->toThrow(LogicException::class);
});

test('the stage lookups answer for the organization asked, in pipeline order', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $options = DefaultPipeline::stageOptions($this->acme->id);
    $foreign = DefaultPipeline::stageFor($other->id, DealStage::Won);

    expect(array_values($options))->toBe(['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Won', 'Lost'])
        ->and(array_keys($options))->not->toContain($foreign->id)
        ->and(DefaultPipeline::stageOfType($this->acme->id, DealStatus::Open)->name)->toBe('Lead')
        ->and(DefaultPipeline::stageOfType($this->acme->id, DealStatus::Won)->name)->toBe('Won')
        ->and(DefaultPipeline::stageOfType($this->acme->id, DealStatus::Lost)->name)->toBe('Lost')
        ->and(fn () => DefaultPipeline::stageById($this->acme->id, $foreign->id))->toThrow(ModelNotFoundException::class);
});

test('an organization without a pipeline gets one when its first deal is created', function () {
    $initech = Organization::withoutEvents(fn () => Organization::factory()->create(['name' => 'Initech']));

    expect(Pipeline::forOrganization($initech->id)->count())->toBe(0);

    $deal = Deal::factory()->create(['organization_id' => $initech->id, 'stage' => DealStage::Lead]);

    $pipelines = Pipeline::forOrganization($initech->id)->get();

    expect($pipelines)->toHaveCount(1)
        ->and($pipelines->first()->name)->toBe('Sales pipeline')
        ->and(PipelineStage::forOrganization($initech->id)->count())->toBe(6)
        ->and(dealPipelineStageOf($deal)->name)->toBe('Lead')
        ->and(dealPipelineStageOf($deal)->organization_id)->toBe($initech->id);
});

test('the factory works when model events are faked', function () {
    $hooli = Organization::withoutEvents(fn () => Organization::factory()->create(['name' => 'Hooli']));

    Event::fake();

    $deal = Deal::factory()->for($hooli)->create(['stage' => DealStage::Won]);

    $pipelineStage = dealPipelineStageOf($deal);

    expect($pipelineStage->name)->toBe('Won')
        ->and($pipelineStage->organization_id)->toBe($hooli->id);
});

test('a stage in use cannot be deleted', function () {
    $deal = Deal::factory()->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id, 'stage' => DealStage::Lead]);
    $stage = dealPipelineStageOf($deal);

    $code = null;

    try {
        DB::transaction(fn () => $stage->delete());
    } catch (QueryException $exception) {
        $code = $exception->getCode();
    }

    expect($code)->toBe('23503')
        ->and(PipelineStage::query()->withoutGlobalScope('organization')->whereKey($stage->id)->exists())->toBeTrue();
});

test('deleting an organization deletes its deals and its pipeline', function () {
    $organization = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $deal = Deal::factory()->create(['organization_id' => $organization->id, 'stage' => DealStage::Won]);

    $organization->delete();

    expect(Deal::query()->withoutGlobalScope('organization')->whereKey($deal->id)->exists())->toBeFalse()
        ->and(Pipeline::query()->withoutGlobalScope('organization')->where('organization_id', $organization->id)->exists())->toBeFalse()
        ->and(PipelineStage::query()->withoutGlobalScope('organization')->where('organization_id', $organization->id)->exists())->toBeFalse();
});

test('the pipeline stage lists its deals', function () {
    $deal = Deal::factory()->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id, 'stage' => DealStage::Lost]);
    $stage = dealPipelineStageOf($deal);

    expect($deal->pipelineStage->is($stage))->toBeTrue()
        ->and($stage->deals->modelKeys())->toBe([$deal->id]);
});
