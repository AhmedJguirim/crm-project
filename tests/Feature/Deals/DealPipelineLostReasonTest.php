<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealStageChange;
use App\Models\LostReason;
use App\Models\Organization;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
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

    $this->stageId = fn (string $stage): int => pipelineStageNamed($this->org->id, $stage)->id;
    $this->lostStageId = fn (): string => (string) ($this->stageId)('Lost');

    $this->deal = Deal::factory()->inStage('Negotiating')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Hosting Renewal',
        'status' => DealStatus::Open,
        'won_at' => null,
        'lost_at' => null,
        'lost_reason_id' => null,
        'lost_reason_note' => null,
        'notes' => null,
        'position' => '1000.0000000000',
    ]);

    $this->lostDeal = fn (string $title, array $attributes = []): Deal => Deal::factory()->inStage('Lost')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => $title,
        'status' => DealStatus::Lost,
        'lost_at' => '2026-10-20 08:00:00',
        'won_at' => null,
        'notes' => null,
        ...$attributes,
    ]);

    $this->globexLostDeal = function (): Deal {
        $contact = Contact::factory()->create(['organization_id' => $this->globex->id]);

        return Deal::factory()->inStage('Lost')->create([
            'organization_id' => $this->globex->id,
            'contact_id' => $contact->id,
            'title' => 'Globex Deal',
            'status' => DealStatus::Lost,
            'lost_at' => '2026-10-20 08:00:00',
            'won_at' => null,
            'lost_reason_id' => null,
            'lost_reason_note' => null,
        ]);
    };

    $this->dragToLost = fn (?Deal $deal = null): Testable => Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) ($deal ?? $this->deal)->id, ($this->lostStageId)());

    $this->asRole = function (OrganizationRole $role): void {
        $member = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($member, ['role' => $role->value]);
        $this->actingAs($member);
        Filament::setTenant($this->org);
    };

    $this->openDrawer = fn (?Deal $deal = null): Testable => Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) ($deal ?? $this->deal)->id]);

    $this->moveStageForm = fn (?Deal $deal = null): Testable => ($this->openDrawer)($deal)
        ->call('mountAction', 'moveStage', [], ['recordKey' => (string) ($deal ?? $this->deal)->id]);

    $this->mountedNames = fn (Testable $component): array => array_column($component->instance()->mountedActions, 'name');

    $this->drawerText = function (Testable $component): string {
        try {
            $html = $component->getMountedActionModalHtml();
        } catch (Throwable) {
            $html = $component->html();
        }

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    };
});

test('dragging into Lost commits the move and opens the prompt', function () {
    $component = ($this->dragToLost)()->assertSet('mountedActions.0.name', 'lostReason');

    $deal = $this->deal->fresh();

    expect($deal->pipeline_stage_id)->toBe(($this->stageId)('Lost'))
        ->and($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_at->toDateTimeString())->toBe('2026-10-21 09:00:00')
        ->and($deal->lost_reason_id)->toBeNull()
        ->and($component->instance()->mountedActions[0]['context']['recordKey'])->toBe((string) $deal->id);
});

test('saving a reason and a note from the prompt', function () {
    $before = DealStageChange::query()->where('deal_id', $this->deal->id)->count();
    $component = ($this->dragToLost)();

    $component
        ->fillForm(['lost_reason_id' => $this->priceTooHigh->id, 'lost_reason_note' => '  Chose a cheaper vendor.  '])
        ->call('callMountedAction')
        ->assertNotified('Lost reason saved');

    $deal = $this->deal->fresh();

    expect($deal->lost_reason_id)->toBe($this->priceTooHigh->id)
        ->and($deal->lost_reason_note)->toBe('Chose a cheaper vendor.')
        ->and(DealStageChange::query()->where('deal_id', $deal->id)->count())->toBe($before + 1);
});

test('the drag writes exactly one history row', function () {
    $before = DealStageChange::query()->where('deal_id', $this->deal->id)->count();

    ($this->dragToLost)();

    expect(DealStageChange::query()->where('deal_id', $this->deal->id)->count())->toBe($before + 1);
});

test('skipping the prompt leaves the deal lost without a reason', function () {
    $component = ($this->dragToLost)()->call('unmountAction')->assertNotNotified();

    $deal = $this->deal->fresh();

    expect($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_reason_id)->toBeNull()
        ->and($deal->lost_reason_note)->toBeNull()
        ->and($component->instance()->mountedActions)->toBe([]);
});

test('saving the prompt empty writes nothing', function () {
    $component = ($this->dragToLost)();
    $updatedAt = $this->deal->fresh()->updated_at->toDateTimeString();

    $this->travelTo(CarbonImmutable::parse('2026-10-21 09:30:00', 'UTC'));

    $component->call('callMountedAction')->assertNotNotified();

    expect($this->deal->fresh()->updated_at->toDateTimeString())->toBe($updatedAt);
});

test('the prompt refuses a reason that is not offered', function (string $which) {
    $component = ($this->dragToLost)();
    $value = match ($which) {
        'deleted' => $this->noResponse->id,
        'other organization' => $this->globexReason->id,
        'unknown' => 999999,
    };

    $component->fillForm(['lost_reason_id' => $value])->call('callMountedAction')->assertHasActionErrors(['lost_reason_id']);

    expect($this->deal->fresh()->lost_reason_id)->toBeNull();
})->with(['deleted', 'other organization', 'unknown']);

test('a deal reopened while the prompt was open is left alone', function () {
    $component = ($this->dragToLost)();

    DealStageMover::move($this->deal->fresh(), pipelineStageNamed($this->org->id, 'Lead'));

    $component
        ->fillForm(['lost_reason_id' => $this->noBudget->id])
        ->call('callMountedAction')
        ->assertNotified('This deal is no longer lost');

    $deal = $this->deal->fresh();

    expect($deal->status)->toBe(DealStatus::Open)
        ->and($deal->pipelineStage->name)->toBe('Lead')
        ->and($deal->lost_reason_id)->toBeNull();
});

test('a deal deleted while the prompt was open does not crash and writes nothing', function () {
    $component = ($this->dragToLost)()->fillForm(['lost_reason_id' => $this->noBudget->id]);

    $this->deal->delete();

    $component->call('callMountedAction')->assertNotNotified();

    expect(Deal::query()->withoutGlobalScopes()->where('lost_reason_id', $this->noBudget->id)->count())->toBe(0);
});

test('dragging a won deal into Lost also asks', function () {
    $won = Deal::factory()->inStage('Won')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Big Win',
        'status' => DealStatus::Won,
        'won_at' => '2026-10-01 09:00:00',
        'lost_at' => null,
        'notes' => null,
    ]);

    ($this->dragToLost)($won)->assertSet('mountedActions.0.name', 'lostReason');

    $won->refresh();

    expect($won->status)->toBe(DealStatus::Lost)->and($won->won_at)->toBeNull();
});

test('reordering inside Lost does not ask and keeps the reason', function () {
    $a = ($this->lostDeal)('Lost A', ['lost_reason_id' => $this->priceTooHigh->id, 'lost_reason_note' => 'Too expensive.', 'position' => '1000.0000000000']);
    $b = ($this->lostDeal)('Lost B', ['position' => '2000.0000000000']);

    $component = Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $a->id, ($this->lostStageId)(), (string) $b->id, null);

    $a->refresh();

    expect($component->instance()->mountedActions)->toBe([])
        ->and($a->lost_reason_id)->toBe($this->priceTooHigh->id)
        ->and($a->lost_reason_note)->toBe('Too expensive.')
        ->and($a->lost_at->toDateTimeString())->toBe('2026-10-20 08:00:00')
        ->and((float) $a->position)->toBeGreaterThan((float) $b->fresh()->position);
});

test('moves that do not end in Lost do not ask', function (string $case) {
    $other = ($this->globexLostDeal)();
    $component = Livewire::test(DealPipeline::class);

    match ($case) {
        'another stage' => $component->call('moveCard', (string) $this->deal->id, (string) ($this->stageId)('Proposal Sent')),
        'won stage' => $component->call('moveCard', (string) $this->deal->id, (string) ($this->stageId)('Won')),
        'forged column' => $component->call('moveCard', (string) $this->deal->id, 'abc'),
        'column of another organization' => $component->call('moveCard', (string) $this->deal->id, (string) pipelineStageNamed($this->globex->id, 'Lost')->id),
        'deal of another organization' => $component->call('moveCard', (string) $other->id, ($this->lostStageId)()),
    };

    expect($component->instance()->mountedActions)->toBe([]);
})->with(['another stage', 'won stage', 'forged column', 'column of another organization', 'deal of another organization']);

test('who is asked after a drag', function (OrganizationRole $role, DealStatus $status, bool $mounted) {
    ($this->asRole)($role);

    $component = ($this->dragToLost)();

    expect($this->deal->fresh()->status)->toBe($status)
        ->and($component->instance()->mountedActions !== [])->toBe($mounted);
})->with([
    'member' => [OrganizationRole::Member, DealStatus::Lost, true],
    'viewer' => [OrganizationRole::Viewer, DealStatus::Open, false],
]);

test('a viewer cannot mount the prompt directly', function () {
    $lost = ($this->lostDeal)('Lost A');

    ($this->asRole)(OrganizationRole::Viewer);

    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', 'lostReason', [], ['recordKey' => (string) $lost->id])
        ->call('callMountedAction', ['lost_reason_id' => $this->noBudget->id]);

    expect($component->instance()->mountedActions)->toBe([])
        ->and($lost->fresh()->lost_reason_id)->toBeNull();
});

test("the prompt pointed at another organization's deal changes nothing", function () {
    $other = ($this->globexLostDeal)();
    $before = $other->fresh()->getAttributes();

    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', 'lostReason', [], ['recordKey' => (string) $other->id])
        ->call('callMountedAction', ['lost_reason_id' => $this->globexReason->id]);

    expect($component->instance()->mountedActions)->toBe([])
        ->and($other->fresh()->getAttributes())->toBe($before);
});

test('the prompt with a malformed key does nothing', function (string $key) {
    $before = $this->deal->fresh()->getAttributes();

    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', 'lostReason', [], ['recordKey' => $key])
        ->call('callMountedAction', ['lost_reason_id' => $this->noBudget->id]);

    expect($component->instance()->mountedActions)->toBe([])
        ->and($this->deal->fresh()->getAttributes())->toBe($before);
})->with(['abc', '0', '-1', '99999999999999999999']);

test('the drawer move stage shows the reason fields only for Lost', function () {
    $component = ($this->moveStageForm)();

    $component->assertSchemaComponentHidden('lost_reason_id', 'mountedActionSchema1')
        ->assertSchemaComponentHidden('lost_reason_note', 'mountedActionSchema1')
        ->fillForm(['pipeline_stage_id' => ($this->stageId)('Lost')])
        ->assertSchemaComponentVisible('lost_reason_id', 'mountedActionSchema1')
        ->assertSchemaComponentVisible('lost_reason_note', 'mountedActionSchema1');
});

test('the drawer move stage to Lost with a reason saves it without opening the prompt', function () {
    $before = DealStageChange::query()->where('deal_id', $this->deal->id)->count();

    $component = ($this->moveStageForm)()
        ->fillForm([
            'pipeline_stage_id' => ($this->stageId)('Lost'),
            'lost_reason_id' => $this->badTiming->id,
            'lost_reason_note' => 'Next year.',
        ])
        ->call('callMountedAction')
        ->assertNotified('Deal moved to Lost');

    $deal = $this->deal->fresh();

    expect($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_reason_id)->toBe($this->badTiming->id)
        ->and($deal->lost_reason_note)->toBe('Next year.')
        ->and(DealStageChange::query()->where('deal_id', $deal->id)->count())->toBe($before + 1)
        ->and(($this->mountedNames)($component))->toBe(['openDeal'])
        ->and(($this->drawerText)($component))->toContain('Lost reason')->toContain('Bad timing')->toContain('Next year.');
});

test('the drawer move stage to Lost without a reason leaves it empty', function () {
    $component = ($this->moveStageForm)()
        ->fillForm(['pipeline_stage_id' => ($this->stageId)('Lost')])
        ->call('callMountedAction');

    $deal = $this->deal->fresh();

    expect($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_reason_id)->toBeNull()
        ->and(($this->mountedNames)($component))->toBe(['openDeal'])
        ->and(($this->drawerText)($component))->toContain('Lost reason')->toContain('No reason given');
});

test("the drawer move stage refuses another organization's reason", function () {
    ($this->moveStageForm)()
        ->fillForm(['pipeline_stage_id' => ($this->stageId)('Lost'), 'lost_reason_id' => $this->globexReason->id])
        ->call('callMountedAction')
        ->assertHasActionErrors(['lost_reason_id']);

    $deal = $this->deal->fresh();

    expect($deal->status)->toBe(DealStatus::Open)->and($deal->pipelineStage->name)->toBe('Negotiating');
});

test('a forged reason with a target that is not Lost is dropped', function () {
    ($this->moveStageForm)()
        ->fillForm(['pipeline_stage_id' => ($this->stageId)('Won'), 'lost_reason_id' => $this->noBudget->id, 'lost_reason_note' => 'x'])
        ->call('callMountedAction');

    $deal = $this->deal->fresh();

    expect($deal->status)->toBe(DealStatus::Won)
        ->and($deal->lost_reason_id)->toBeNull()
        ->and($deal->lost_reason_note)->toBeNull();
});

test('the drawer shows the lost reason of a lost deal', function () {
    $lost = ($this->lostDeal)('Lost A', ['lost_reason_id' => $this->priceTooHigh->id, 'lost_reason_note' => "Line one\nLine two"]);

    expect(($this->drawerText)(($this->openDrawer)($lost)))
        ->toContain('Lost reason')->toContain('Price too high')->toContain('Lost note')->toContain('Line one');
});

test('the drawer shows a deleted reason as deleted', function () {
    $lost = ($this->lostDeal)('Lost A', ['lost_reason_id' => $this->noBudget->id]);
    $this->noBudget->delete();

    expect(($this->drawerText)(($this->openDrawer)($lost)))->toContain('No budget (deleted)');
});

test('the drawer of a lost deal without a reason says so and has no note line', function () {
    $lost = ($this->lostDeal)('Lost A', ['lost_reason_id' => null, 'lost_reason_note' => null]);

    expect(($this->drawerText)(($this->openDrawer)($lost)))->toContain('No reason given')->not->toContain('Lost note');
});

test('the drawer of an open deal has no lost lines', function () {
    expect(($this->drawerText)(($this->openDrawer)()))->not->toContain('Lost reason')->not->toContain('Lost note');
});
