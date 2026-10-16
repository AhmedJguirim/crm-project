<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\DealStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use App\Services\Deals\PipelineStageOrder;
use App\Services\Segments\SegmentQueryBuilder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->pipeline = DefaultPipeline::ensureFor($this->org);
    $this->globex = Organization::factory()->create();
    $this->globexPipeline = DefaultPipeline::ensureFor($this->globex);

    $this->id = fn (string $name, ?Organization $org = null): int => pipelineStageNamed($org ?? $this->org, $name)->id;
    $this->ids = fn (array $names): array => array_map(fn (string $name): string => (string) ($this->id)($name), $names);
    $this->positions = fn (?Pipeline $pipeline = null): array => PipelineStage::forOrganization(($pipeline ?? $this->pipeline)->organization_id)
        ->where('pipeline_id', ($pipeline ?? $this->pipeline)->getKey())
        ->orderBy('position')->orderBy('id')
        ->pluck('position', 'name')->all();
});

it('puts a new open stage just before Won', function () {
    $stage = PipelineStageOrder::addOpenStage($this->pipeline, ['name' => 'Qualified', 'color' => 'info', 'probability' => 60]);

    expect(($this->positions)())->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Qualified' => 5, 'Won' => 6, 'Lost' => 7])
        ->and($stage->type)->toBe(DealStatus::Open)
        ->and($stage->organization_id)->toBe($this->org->id)
        ->and($stage->pipeline_id)->toBe($this->pipeline->id)
        ->and($stage->color)->toBe('info')
        ->and($stage->probability)->toBe(60)
        ->and($stage->position)->toBe(5);
});

it('drops forged attributes on add and does not read the panel tenant', function () {
    expect(Filament::getTenant())->toBeNull();

    $stage = PipelineStageOrder::addOpenStage($this->pipeline, [
        'name' => 'X', 'color' => 'gray', 'probability' => 5,
        'type' => 'won', 'position' => 1, 'organization_id' => $this->globex->id, 'pipeline_id' => $this->globexPipeline->id,
    ]);

    expect($stage->type)->toBe(DealStatus::Open)
        ->and($stage->organization_id)->toBe($this->org->id)
        ->and($stage->pipeline_id)->toBe($this->pipeline->id)
        ->and(($this->positions)())->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'X' => 5, 'Won' => 6, 'Lost' => 7])
        ->and(($this->positions)($this->globexPipeline))->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6]);
});

it('reorders the open stages', function () {
    $moved = PipelineStageOrder::reorderOpenStages($this->pipeline, ($this->ids)(['Negotiating', 'Lead', 'Discovery', 'Proposal Sent', 'Won', 'Lost']));

    expect($moved)->toBeFalse()
        ->and(($this->positions)())->toBe(['Negotiating' => 1, 'Lead' => 2, 'Discovery' => 3, 'Proposal Sent' => 4, 'Won' => 5, 'Lost' => 6]);
});

it('does not let Won and Lost move above an open stage', function () {
    $moved = PipelineStageOrder::reorderOpenStages($this->pipeline, ($this->ids)(['Won', 'Lead', 'Lost', 'Discovery', 'Proposal Sent', 'Negotiating']));

    expect($moved)->toBeTrue()
        ->and(($this->positions)())->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6]);
});

it('keeps Won before Lost when the browser swaps them at the end', function () {
    $moved = PipelineStageOrder::reorderOpenStages($this->pipeline, ($this->ids)(['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Lost', 'Won']));

    expect($moved)->toBeFalse()
        ->and(($this->positions)())->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6]);
});

it('keeps the open stages missing from a partial order after the given ones', function () {
    PipelineStageOrder::reorderOpenStages($this->pipeline, ($this->ids)(['Proposal Sent']));

    expect(($this->positions)())->toBe(['Proposal Sent' => 1, 'Lead' => 2, 'Discovery' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6]);
});

it('ignores forged keys and leaves other organizations alone', function (Closure $forged) {
    PipelineStageOrder::reorderOpenStages($this->pipeline, [$forged($this), (string) ($this->id)('Negotiating')]);

    expect(($this->positions)())->toBe(['Negotiating' => 1, 'Lead' => 2, 'Discovery' => 3, 'Proposal Sent' => 4, 'Won' => 5, 'Lost' => 6])
        ->and(($this->positions)($this->globexPipeline))->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6]);
})->with([
    'a stage of another organization' => [fn ($test): string => (string) ($test->id)('Lead', $test->globex)],
    'an open stage of a second pipeline of the same organization' => [function ($test): string {
        $second = Pipeline::factory()->create(['organization_id' => $test->org->id]);

        return (string) PipelineStage::factory()->create(['pipeline_id' => $second->id, 'name' => 'Other', 'position' => 1])->id;
    }],
    'text' => [fn (): string => 'abc'],
    'zero' => [fn (): string => '0'],
    'negative' => [fn (): string => '-3'],
    'decimal' => [fn (): string => '1.5'],
    'unknown id' => [fn (): int => 999999],
    'a duplicate' => [fn ($test): string => (string) ($test->id)('Negotiating')],
]);

it('heals duplicate and gapped positions', function () {
    foreach (['Lead' => 1, 'Discovery' => 1, 'Proposal Sent' => 7, 'Negotiating' => 9, 'Won' => 20, 'Lost' => 20] as $name => $position) {
        pipelineStageNamed($this->org, $name)->forceFill(['position' => $position])->save();
    }

    PipelineStageOrder::reorderOpenStages($this->pipeline, []);

    expect(($this->positions)())->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6]);
});

it('changes positions only: deals and segment members stay the same', function () {
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $deal = Deal::factory()->inStage('Lead')->create(['organization_id' => $this->org->id, 'contact_id' => $contact->id]);
    $lead = pipelineStageNamed($this->org, 'Lead');
    $rule = new SegmentRuleData('rule-1', 'Lead deal', [
        SegmentConditionData::make(SegmentConditionType::Deal, null, SegmentOperator::HasDeal, ['deal_stages' => [$lead->id]]),
    ]);
    $members = fn (): array => SegmentQueryBuilder::forOrganization($this->org->id)->matching([$rule])->pluck('contacts.id')->all();

    expect($members())->toBe([$contact->id]);

    PipelineStageOrder::reorderOpenStages($this->pipeline, ($this->ids)(['Negotiating', 'Lead', 'Discovery', 'Proposal Sent']));
    PipelineStageOrder::addOpenStage($this->pipeline, ['name' => 'Qualified', 'color' => 'info', 'probability' => 60]);

    expect($deal->fresh()->pipeline_stage_id)->toBe($lead->id)
        ->and($members())->toBe([$contact->id]);
});
