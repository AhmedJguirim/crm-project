<?php

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
use Illuminate\Support\Facades\Schema;
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

test('a deal created in a named stage gets that stage of its organization\'s pipeline', function (string $name, int $position, DealStatus $type) {
    $deal = Deal::factory()->inStage($name)->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id]);

    $pipelineStage = dealPipelineStageOf($deal);
    $acmePipeline = Pipeline::forOrganization($this->acme->id)->oldest('id')->firstOrFail();

    expect($pipelineStage->pipeline_id)->toBe($acmePipeline->id)
        ->and($pipelineStage->organization_id)->toBe($this->acme->id)
        ->and($pipelineStage->name)->toBe($name)
        ->and($pipelineStage->position)->toBe($position)
        ->and($pipelineStage->type)->toBe($type);
})->with([
    'lead' => ['Lead', 1, DealStatus::Open],
    'discovery' => ['Discovery', 2, DealStatus::Open],
    'proposal sent' => ['Proposal Sent', 3, DealStatus::Open],
    'negotiating' => ['Negotiating', 4, DealStatus::Open],
    'won' => ['Won', 5, DealStatus::Won],
    'lost' => ['Lost', 6, DealStatus::Lost],
]);

test('a deal created without a stage is in the Lead stage of its organization', function () {
    $deal = Deal::factory()->for($this->acme)->create(['created_by' => $this->owner->id]);

    expect(dealPipelineStageOf($deal)->is(pipelineStageNamed($this->acme, 'Lead')))->toBeTrue()
        ->and($deal->fresh()->status)->toBe(DealStatus::Open);
});

test('inStage with an unknown stage name fails loudly', function () {
    expect(fn () => Deal::factory()->for($this->acme)->inStage('Closed')->create())
        ->toThrow(InvalidArgumentException::class, 'No pipeline stage named "Closed".');
});

test('moving a deal moves its pipeline stage', function () {
    $deal = Deal::factory()->inStage('Lead')->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id]);

    DealStageMover::move($deal, pipelineStageNamed($this->acme, 'Won'));

    $pipelineStage = dealPipelineStageOf($deal);

    expect($pipelineStage->name)->toBe('Won')
        ->and($pipelineStage->type)->toBe(DealStatus::Won)
        ->and($pipelineStage->probability)->toBe(100);
});

test('a save that does not touch the stage keeps the pipeline stage', function () {
    $deal = Deal::factory()->inStage('Discovery')->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id]);
    $lostStage = PipelineStage::forOrganization($this->acme->id)->where('position', 6)->firstOrFail();

    $deal->forceFill(['pipeline_stage_id' => $lostStage->id])->saveQuietly();
    $deal->update(['title' => 'Renamed']);

    expect($deal->fresh()->pipeline_stage_id)->toBe($lostStage->id)
        ->and($deal->fresh()->title)->toBe('Renamed');
});

test('a deal created from the deal form gets its pipeline stage', function () {
    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Form deal', 'pipeline_stage_id' => pipelineStageNamed($this->acme, 'Proposal Sent')->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'Form deal')->firstOrFail();

    expect(dealPipelineStageOf($deal)->name)->toBe('Proposal Sent')
        ->and(dealPipelineStageOf($deal)->organization_id)->toBe($this->acme->id);
});

test('a deal of another organization gets that organization stage, even under another tenant', function () {
    $globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $globexPipeline = Pipeline::forOrganization($globex->id)->oldest('id')->firstOrFail();

    $deal = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $globex->id]);

    $pipelineStage = dealPipelineStageOf($deal);

    expect($pipelineStage->pipeline_id)->toBe($globexPipeline->id)
        ->and($pipelineStage->organization_id)->toBe($globex->id)
        ->and($pipelineStage->name)->toBe('Negotiating');
});

test('a deal given the pipeline stage of another organization is refused on creation and on update', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreignLead = pipelineStageNamed($other, 'Lead');
    $deal = Deal::factory()->inStage('Discovery')->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id]);

    expect(fn () => Deal::factory()->create([
        'organization_id' => $this->acme->id,
        'created_by' => $this->owner->id,
        'pipeline_stage_id' => $foreignLead->id,
    ]))->toThrow(ModelNotFoundException::class);

    expect(fn () => $deal->update(['pipeline_stage_id' => $foreignLead->id]))->toThrow(ModelNotFoundException::class)
        ->and(dealPipelineStageOf($deal)->name)->toBe('Discovery');
});

test('a deal created or moved by stage id keeps exactly that stage', function (string $name) {
    $deal = Deal::query()->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id, 'title' => 'Kept', 'status' => DealStatus::Open, 'pipeline_stage_id' => pipelineStageNamed($this->acme, $name)->id]);

    expect($deal->fresh()->pipeline_stage_id)->toBe(pipelineStageNamed($this->acme, $name)->id);

    $deal->update(['pipeline_stage_id' => pipelineStageNamed($this->acme, 'Lead')->id]);

    expect($deal->fresh()->pipeline_stage_id)->toBe(pipelineStageNamed($this->acme, 'Lead')->id);
})->with(['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Won', 'Lost']);

test('a stage attribute is no longer stored', function () {
    $deal = Deal::factory()->inStage('Lead')->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id]);
    $before = $deal->fresh();

    $deal->update(['stage' => 'won']);

    $after = $deal->fresh();

    expect(Schema::hasColumn('deals', 'stage'))->toBeFalse()
        ->and($after->pipeline_stage_id)->toBe($before->pipeline_stage_id)
        ->and($after->status)->toBe($before->status)
        ->and($after->getAttributes())->not->toHaveKey('stage');
});

test('a deal created without a pipeline stage is refused by the database', function () {
    expect(fn () => DB::transaction(fn () => Deal::query()->create([
        'organization_id' => $this->acme->id,
        'created_by' => $this->owner->id,
        'title' => 'No stage',
        'status' => DealStatus::Open,
    ])))->toThrow(QueryException::class, 'pipeline_stage_id');
});

test('the legacy stage helpers are gone', function () {
    expect(method_exists(DefaultPipeline::class, 'stageFor'))->toBeFalse()
        ->and(method_exists(DefaultPipeline::class, 'legacyStageFor'))->toBeFalse()
        ->and(method_exists(Deal::class, 'scopeByStage'))->toBeFalse();
});

test('the stage lookups answer for the organization asked, in pipeline order', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $options = DefaultPipeline::stageOptions($this->acme->id);
    $foreign = pipelineStageNamed($other, 'Won');

    expect(array_values($options))->toBe(['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Won', 'Lost'])
        ->and(array_keys($options))->not->toContain($foreign->id)
        ->and(DefaultPipeline::stageOfType($this->acme->id, DealStatus::Open)->name)->toBe('Lead')
        ->and(DefaultPipeline::stageOfType($this->acme->id, DealStatus::Won)->name)->toBe('Won')
        ->and(DefaultPipeline::stageOfType($this->acme->id, DealStatus::Lost)->name)->toBe('Lost')
        ->and(fn () => DefaultPipeline::stageById($this->acme->id, $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(pipelineStageNamed($other, 'Won')->is($foreign))->toBeTrue()
        ->and(pipelineStageNamed($other->id, 'Won')->organization_id)->toBe($other->id);
});

test('an organization without a pipeline gets one when its first deal is created', function () {
    $initech = Organization::withoutEvents(fn () => Organization::factory()->create(['name' => 'Initech']));

    expect(Pipeline::forOrganization($initech->id)->count())->toBe(0);

    $deal = Deal::factory()->inStage('Lead')->create(['organization_id' => $initech->id]);

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

    $deal = Deal::factory()->for($hooli)->inStage('Won')->create();

    $pipelineStage = dealPipelineStageOf($deal);

    expect($pipelineStage->name)->toBe('Won')
        ->and($pipelineStage->organization_id)->toBe($hooli->id);
});

test('a stage in use cannot be deleted', function () {
    $deal = Deal::factory()->inStage('Lead')->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id]);
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
    $deal = Deal::factory()->inStage('Won')->create(['organization_id' => $organization->id]);

    $organization->delete();

    expect(Deal::query()->withoutGlobalScope('organization')->whereKey($deal->id)->exists())->toBeFalse()
        ->and(Pipeline::query()->withoutGlobalScope('organization')->where('organization_id', $organization->id)->exists())->toBeFalse()
        ->and(PipelineStage::query()->withoutGlobalScope('organization')->where('organization_id', $organization->id)->exists())->toBeFalse();
});

test('the pipeline stage lists its deals', function () {
    $deal = Deal::factory()->inStage('Lost')->create(['organization_id' => $this->acme->id, 'created_by' => $this->owner->id]);
    $stage = dealPipelineStageOf($deal);

    expect($deal->pipelineStage->is($stage))->toBeTrue()
        ->and($stage->deals->modelKeys())->toBe([$deal->id]);
});
