<?php

use App\Enums\DealStatus;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->acme = Organization::factory()->create(['name' => 'Acme']);
    $this->globex = Organization::factory()->create(['name' => 'Globex']);
    $this->acmePipeline = Pipeline::forOrganization($this->acme->id)->firstOrFail();
});

test('a new organization gets the default pipeline with its six typed stages', function () {
    $pipelines = Pipeline::forOrganization($this->acme->id)->get();

    expect($pipelines)->toHaveCount(1)
        ->and($pipelines->first()->name)->toBe('Sales pipeline')
        ->and($pipelines->first()->organization_id)->toBe($this->acme->id);

    $stages = PipelineStage::forOrganization($this->acme->id)->orderBy('position')->get();

    expect($stages->map(fn (PipelineStage $stage): array => [
        $stage->position, $stage->name, $stage->type, $stage->color, $stage->probability,
    ])->all())->toBe([
        [1, 'Lead', DealStatus::Open, 'gray', 10],
        [2, 'Discovery', DealStatus::Open, 'info', 25],
        [3, 'Proposal Sent', DealStatus::Open, 'warning', 50],
        [4, 'Negotiating', DealStatus::Open, 'orange', 75],
        [5, 'Won', DealStatus::Won, 'success', 100],
        [6, 'Lost', DealStatus::Lost, 'gray', 0],
    ])
        ->and($stages->pluck('organization_id')->unique()->all())->toBe([$this->acme->id])
        ->and($stages->pluck('pipeline_id')->unique()->all())->toBe([$this->acmePipeline->id]);
});

test('the sign-up path and the register organization page both provision the pipeline', function () {
    $user = User::factory()->onboardingCompleted()->create();
    $personal = $user->createPersonalOrganization();

    expect(Pipeline::forOrganization($personal->id)->count())->toBe(1)
        ->and(PipelineStage::forOrganization($personal->id)->count())->toBe(6);

    $this->actingAs($user);
    Filament::setTenant($personal);

    Livewire::test(RegisterOrganization::class)
        ->fillForm(['name' => 'Second Org'])
        ->call('register')
        ->assertHasNoFormErrors();

    $second = Organization::query()->where('name', 'Second Org')->firstOrFail();

    expect(Pipeline::forOrganization($second->id)->count())->toBe(1)
        ->and(PipelineStage::forOrganization($second->id)->count())->toBe(6);
});

test('provisioning is idempotent', function () {
    $again = DefaultPipeline::ensureFor($this->acme);

    expect($again->id)->toBe($this->acmePipeline->id)
        ->and(Pipeline::forOrganization($this->acme->id)->count())->toBe(1)
        ->and(PipelineStage::forOrganization($this->acme->id)->count())->toBe(6);
});

test('the default pipeline is the oldest one', function () {
    $this->actingAs(User::factory()->create());
    Filament::setTenant($this->acme);

    Pipeline::factory()->create(['organization_id' => $this->acme->id, 'name' => 'Partners']);

    expect($this->acme->defaultPipeline->id)->toBe($this->acmePipeline->id)
        ->and($this->acme->pipelines()->count())->toBe(2)
        ->and(DefaultPipeline::ensureFor($this->acme)->id)->toBe($this->acmePipeline->id);
});

test('pipelines and stages are scoped to the current organization and fail closed', function () {
    $this->actingAs(User::factory()->create());
    Filament::setTenant($this->acme);

    expect(Pipeline::query()->pluck('organization_id')->unique()->all())->toBe([$this->acme->id])
        ->and(PipelineStage::query()->count())->toBe(6)
        ->and(PipelineStage::query()->pluck('organization_id')->unique()->all())->toBe([$this->acme->id]);

    Filament::setTenant(null);

    expect(Pipeline::query()->count())->toBe(0)
        ->and(PipelineStage::query()->count())->toBe(0);
});

test('the database refuses an invalid stage', function (array $attributes) {
    expect(fn () => DB::transaction(fn () => PipelineStage::factory()->create([
        'pipeline_id' => $this->acmePipeline->id,
        'position' => 7,
        ...$attributes,
    ])))->toThrow(QueryException::class);
})->with([
    'probability above 100' => [['probability' => 101]],
    'a negative probability' => [['probability' => -1]],
    'a second won stage' => [['type' => DealStatus::Won, 'probability' => 100]],
    'a second lost stage' => [['type' => DealStatus::Lost, 'probability' => 0]],
]);

test('the database refuses an unknown stage type', function () {
    expect(fn () => DB::transaction(fn () => DB::table('pipeline_stages')->insert([
        'organization_id' => $this->acme->id,
        'pipeline_id' => $this->acmePipeline->id,
        'name' => 'Pending',
        'type' => 'pending',
        'color' => 'gray',
        'position' => 7,
        'probability' => 50,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);
});

test('the limits of the probability are accepted', function (int $probability) {
    $stage = PipelineStage::factory()->create([
        'pipeline_id' => $this->acmePipeline->id,
        'position' => 7,
        'probability' => $probability,
    ]);

    expect($stage->fresh()->probability)->toBe($probability);
})->with([0, 100]);

test('several open stages are allowed in one pipeline', function () {
    $stage = PipelineStage::factory()->create([
        'pipeline_id' => $this->acmePipeline->id,
        'name' => 'Demo',
        'position' => 7,
        'probability' => 40,
    ]);

    expect($stage->fresh()->type)->toBe(DealStatus::Open)
        ->and($stage->organization_id)->toBe($this->acme->id)
        ->and(PipelineStage::forOrganization($this->acme->id)->count())->toBe(7);
});

test('another organization keeps its own won and lost stage', function () {
    expect(PipelineStage::forOrganization($this->globex->id)->where('type', DealStatus::Won)->count())->toBe(1)
        ->and(PipelineStage::forOrganization($this->acme->id)->where('type', DealStatus::Won)->count())->toBe(1);
});

test('deleting an organization deletes its pipelines and stages', function () {
    $this->acme->delete();

    expect(Pipeline::forOrganization($this->acme->id)->count())->toBe(0)
        ->and(PipelineStage::forOrganization($this->acme->id)->count())->toBe(0)
        ->and(Pipeline::forOrganization($this->globex->id)->count())->toBe(1)
        ->and(PipelineStage::forOrganization($this->globex->id)->count())->toBe(6);
});
