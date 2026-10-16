<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\DealStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Exceptions\PipelineStageCannotBeDeleted;
use App\Exceptions\UsedInSegmentsException;
use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Models\Segment;
use App\Models\User;
use App\Services\Deals\DefaultPipeline;
use App\Services\Deals\PipelineStageOrder;
use App\Services\Segments\SegmentQueryBuilder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Security\SecurityPayloads;

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

describe('delete an open stage', function () {
    beforeEach(function () {
        $this->deal = fn (string $stage, string $title, ?Organization $org = null): Deal => Deal::factory()->inStage($stage)->create([
            'organization_id' => ($org ?? $this->org)->id,
            'title' => $title,
            'contact_id' => Contact::factory()->create(['organization_id' => ($org ?? $this->org)->id])->id,
        ]);
        $this->stageOf = fn (): array => Deal::forOrganization($this->org->id)->orderBy('id')->pluck('pipeline_stage_id', 'title')->all();
        $this->stageRows = fn (): array => PipelineStage::forOrganization($this->org->id)->orderBy('id')->pluck('position', 'id')->all();
        $this->delete = fn (string $name, mixed $target = null): int => PipelineStageOrder::deleteOpenStage($this->pipeline, pipelineStageNamed($this->org, $name), $target);
    });

    it('moves the deals to the chosen stage and renumbers the positions', function () {
        ($this->deal)('Proposal Sent', 'Retainer');
        ($this->deal)('Proposal Sent', 'Audit prep');
        $discovery = ($this->id)('Discovery');

        $moved = ($this->delete)('Proposal Sent', $discovery);

        expect($moved)->toBe(2)
            ->and(($this->stageOf)())->toBe(['Retainer' => $discovery, 'Audit prep' => $discovery])
            ->and(Deal::forOrganization($this->org->id)->get()->every(fn (Deal $deal): bool => $deal->status === DealStatus::Open && $deal->won_at === null && $deal->lost_at === null))->toBeTrue()
            ->and(($this->positions)())->toBe(['Lead' => 1, 'Discovery' => 2, 'Negotiating' => 3, 'Won' => 4, 'Lost' => 5]);
    });

    it('accepts the target as a numeric string', function () {
        $deal = ($this->deal)('Proposal Sent', 'Retainer');

        ($this->delete)('Proposal Sent', (string) ($this->id)('Lead'));

        expect($deal->fresh()->pipeline_stage_id)->toBe(($this->id)('Lead'));
    });

    it('moves the deals through model events so the segments are resynced', function () {
        $deal = ($this->deal)('Proposal Sent', 'Retainer');
        Segment::factory()->for($this->org)->published()->create(['name' => 'Anyone']);
        Queue::fake();

        ($this->delete)('Proposal Sent', ($this->id)('Discovery'));

        Queue::assertPushed(ResyncContactSegments::class, fn (ResyncContactSegments $job): bool => $job->contactId === $deal->contact_id);
    });

    it('deletes a stage without deals and ignores the target', function () {
        expect(($this->delete)('Lead'))->toBe(0)
            ->and(($this->positions)())->toBe(['Discovery' => 1, 'Proposal Sent' => 2, 'Negotiating' => 3, 'Won' => 4, 'Lost' => 5]);

        expect(($this->delete)('Discovery', 'junk'))->toBe(0)
            ->and(($this->positions)())->toBe(['Proposal Sent' => 1, 'Negotiating' => 2, 'Won' => 3, 'Lost' => 4]);
    });

    it('refuses and changes nothing', function (string $stage, Closure $target, string $message) {
        ($this->deal)('Proposal Sent', 'Retainer');
        ($this->deal)('Won', 'Audit');
        $before = [($this->positions)(), ($this->stageOf)(), ($this->stageRows)()];

        expect(fn () => ($this->delete)($stage, $target($this)))->toThrow(PipelineStageCannotBeDeleted::class, $message);

        expect([($this->positions)(), ($this->stageOf)(), ($this->stageRows)()])->toBe($before);
    })->with([
        'Won' => ['Won', fn ($test): int => ($test->id)('Discovery'), 'Won and Lost cannot be deleted: every pipeline keeps one of each.'],
        'Lost' => ['Lost', fn ($test): int => ($test->id)('Discovery'), 'Won and Lost cannot be deleted: every pipeline keeps one of each.'],
        'no target' => ['Proposal Sent', fn (): mixed => null, 'Choose another open stage of this pipeline to move the deals to.'],
        'a text target' => ['Proposal Sent', fn (): string => 'abc', 'Choose another open stage of this pipeline to move the deals to.'],
        'the stage itself' => ['Proposal Sent', fn ($test): int => ($test->id)('Proposal Sent'), 'Choose another open stage of this pipeline to move the deals to.'],
        'Won as the target' => ['Proposal Sent', fn ($test): int => ($test->id)('Won'), 'Choose another open stage of this pipeline to move the deals to.'],
        'a stage of another organization' => ['Proposal Sent', fn ($test): int => ($test->id)('Discovery', $test->globex), 'Choose another open stage of this pipeline to move the deals to.'],
        'a decimal target' => ['Proposal Sent', fn ($test): string => ($test->id)('Discovery').'.5', 'Choose another open stage of this pipeline to move the deals to.'],
    ]);

    it('refuses to delete the last open stage', function () {
        foreach (['Lead', 'Discovery', 'Negotiating'] as $name) {
            ($this->delete)($name);
        }

        expect(fn () => ($this->delete)('Proposal Sent'))->toThrow(PipelineStageCannotBeDeleted::class, 'The stage "Proposal Sent" cannot be deleted: a pipeline needs at least one open stage.')
            ->and(($this->positions)())->toBe(['Proposal Sent' => 1, 'Won' => 2, 'Lost' => 3]);
    });

    it('refuses a stage used by a segment before any deal moves', function () {
        $deal = ($this->deal)('Negotiating', 'Retainer');
        Segment::factory()->for($this->org)->create([
            'name' => 'Hot deals',
            'draft_rules' => [(new SegmentRuleData('rule-1', 'Rule', [
                SegmentConditionData::make(SegmentConditionType::Deal, null, SegmentOperator::HasNoDeal, ['deal_stages' => [(string) ($this->id)('Negotiating')]]),
            ]))->toArray()],
        ]);

        Segment::factory()->for($this->org)->published()->create(['name' => 'Anyone']);
        Queue::fake();

        expect(fn () => ($this->delete)('Negotiating', ($this->id)('Discovery')))
            ->toThrow(UsedInSegmentsException::class, 'The pipeline stage "Negotiating" cannot be deleted: it is used in the conditions of the segment "Hot deals". Remove it from those conditions first.');

        Queue::assertNothingPushed();
        expect($deal->fresh()->pipeline_stage_id)->toBe(($this->id)('Negotiating'))
            ->and(($this->positions)())->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6]);
    });

    it('locks the stage row before the deals are moved', function () {
        ($this->deal)('Proposal Sent', 'Retainer');
        $stageId = ($this->id)('Proposal Sent');
        $target = ($this->id)('Discovery');

        $queries = SecurityPayloads::queriesDuring(fn () => ($this->delete)('Proposal Sent', $target));

        $lock = collect($queries)->search(fn (string $sql): bool => str_contains($sql, 'from "pipeline_stages"') && str_contains($sql, '"id" = ') && str_ends_with(trim($sql), 'for update'));
        $firstMove = collect($queries)->search(fn (string $sql): bool => str_starts_with($sql, 'update "deals"'));

        expect($lock)->not->toBeFalse()
            ->and($firstMove)->not->toBeFalse()
            ->and($lock)->toBeLessThan($firstMove);
    });

    it('leaves the other organizations alone', function () {
        $foreign = ($this->deal)('Proposal Sent', 'Globex deal', $this->globex);
        ($this->deal)('Proposal Sent', 'Retainer');

        ($this->delete)('Proposal Sent', ($this->id)('Discovery'));

        expect(($this->positions)($this->globexPipeline))->toBe(['Lead' => 1, 'Discovery' => 2, 'Proposal Sent' => 3, 'Negotiating' => 4, 'Won' => 5, 'Lost' => 6])
            ->and($foreign->fresh()->pipeline_stage_id)->toBe(($this->id)('Proposal Sent', $this->globex));
    });

    it('fails with a missing stage when the stage is not in the pipeline', function () {
        expect(fn () => PipelineStageOrder::deleteOpenStage($this->pipeline, pipelineStageNamed($this->globex, 'Lead'), null))
            ->toThrow(ModelNotFoundException::class);
        expect(PipelineStage::forOrganization($this->globex->id)->count())->toBe(6);
    });
});
