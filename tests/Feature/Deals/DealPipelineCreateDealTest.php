<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Segment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:15:00'));

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Olivia Owner']);
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->jane = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);

    $this->oldWon = Deal::factory()->inStage('Won')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->owner->id,
        'title' => 'Old won',
        'status' => DealStatus::Won,
        'won_at' => '2026-10-01 10:00:00',
        'position' => '1000.0000000000',
    ]);

    $this->stageId = fn (string $stage): int => pipelineStageNamed($this->org->id, $stage)->getKey();

    $this->asRole = function (OrganizationRole $role): void {
        $member = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($member, ['role' => $role->value]);
        $this->actingAs($member);
        Filament::setTenant($this->org);
    };

    $this->cardColumn = function (Testable $component, Deal $deal): ?string {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$component->html(), LIBXML_NOERROR);

        foreach ($dom->getElementsByTagName('*') as $element) {
            if ($element->getAttribute('data-card-id') !== (string) $deal->id) {
                continue;
            }

            $parent = $element->parentNode;

            while ($parent instanceof DOMElement) {
                if ($parent->hasAttribute('data-column-id')) {
                    return $parent->getAttribute('data-column-id');
                }

                $parent = $parent->parentNode;
            }
        }

        return null;
    };
});

test('the header button opens the deal form with the create defaults', function () {
    Livewire::test(DealPipeline::class)
        ->assertActionExists('create', fn (Action $action): bool => $action->getUrl() === null && ! $action->canCreateAnother())
        ->mountAction('create')
        ->assertSchemaStateSet(['owner_id' => $this->owner->id, 'pipeline_stage_id' => ($this->stageId)('Lead')]);
});

test('the creation attributes carry the tenant, the creator and the stage dates', function () {
    $attributes = DealForm::mutateDataBeforeCreate(['title' => 'Direct', 'pipeline_stage_id' => ($this->stageId)('Won')]);

    expect($attributes['organization_id'])->toBe($this->org->id)
        ->and($attributes['created_by'])->toBe($this->owner->id)
        ->and($attributes['status'])->toBe(DealStatus::Won);
});

test('creating a deal keeps me on the board and shows the card', function () {
    $component = Livewire::test(DealPipeline::class)
        ->callAction('create', [
            'title' => 'Board deal',
            'contact_id' => $this->jane->id,
            'pipeline_stage_id' => ($this->stageId)('Proposal Sent'),
            'value' => '1500',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Deal created successfully')
        ->assertActionNotMounted();

    $deal = Deal::query()->where('title', 'Board deal')->firstOrFail();

    expect($deal->organization_id)->toBe($this->org->id)
        ->and($deal->created_by)->toBe($this->owner->id)
        ->and($deal->owner_id)->toBe($this->owner->id)
        ->and($deal->status)->toBe(DealStatus::Open)
        ->and($deal->won_at)->toBeNull()
        ->and($deal->lost_at)->toBeNull()
        ->and($deal->position)->toBeNull()
        ->and(($this->cardColumn)($component, $deal))->toBe((string) ($this->stageId)('Proposal Sent'));
});

test('the stage decides the status and the dates', function (string $stage, DealStatus $status, bool $won) {
    Livewire::test(DealPipeline::class)
        ->callAction('create', ['title' => 'Closed deal', 'pipeline_stage_id' => ($this->stageId)($stage)])
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'Closed deal')->firstOrFail();

    expect($deal->status)->toBe($status)
        ->and($deal->won_at?->toDateTimeString())->toBe($won ? '2026-10-14 09:15:00' : null)
        ->and($deal->lost_at?->toDateTimeString())->toBe($won ? null : '2026-10-14 09:15:00');
})->with([
    'won' => ['Won', DealStatus::Won, true],
    'lost' => ['Lost', DealStatus::Lost, false],
]);

test('a new won card goes to the bottom of its column', function () {
    $component = Livewire::test(DealPipeline::class)
        ->callAction('create', ['title' => 'Board won', 'pipeline_stage_id' => ($this->stageId)('Won')])
        ->assertHasNoFormErrors();

    expect($component->instance()->getBoard()->getBoardRecords((string) ($this->stageId)('Won'))->pluck('title')->all())->toBe(['Old won', 'Board won']);
});

test('the contact segments are resynced', function () {
    Segment::factory()->for($this->org)->published()->create();
    Queue::fake([ResyncContactSegments::class]);

    Livewire::test(DealPipeline::class)
        ->callAction('create', ['title' => 'Board deal', 'contact_id' => $this->jane->id, 'pipeline_stage_id' => ($this->stageId)('Lead')])
        ->assertHasNoFormErrors();

    Queue::assertPushed(ResyncContactSegments::class, fn (ResyncContactSegments $job): bool => $job->contactId === $this->jane->id);
});

test('the title is required', function () {
    Livewire::test(DealPipeline::class)
        ->callAction('create', ['title' => '', 'pipeline_stage_id' => ($this->stageId)('Lead')])
        ->assertHasFormErrors(['title' => 'required']);

    expect(Deal::query()->count())->toBe(1);
});

test('a contact of another organization is refused', function () {
    $gina = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $secret = Contact::factory()->create([
        'organization_id' => $gina->personalOrganization()->id,
        'name' => 'Globex Secret Contact',
    ]);

    Livewire::test(DealPipeline::class)
        ->callAction('create', ['title' => 'Forged', 'contact_id' => $secret->id, 'pipeline_stage_id' => ($this->stageId)('Lead')])
        ->assertHasFormErrors(['contact_id']);

    expect(Deal::query()->withoutGlobalScopes()->where('title', 'Forged')->exists())->toBeFalse();
});

test('only roles that can create deals see the button', function (OrganizationRole $role, bool $visible) {
    ($this->asRole)($role);

    $component = Livewire::test(DealPipeline::class);

    $visible
        ? $component->assertActionVisible('create')
        : $component->assertActionHidden('create');
})->with([
    'owner' => [OrganizationRole::Owner, true],
    'admin' => [OrganizationRole::Admin, true],
    'member' => [OrganizationRole::Member, true],
    'viewer' => [OrganizationRole::Viewer, false],
]);

test('a viewer forged mount is refused', function () {
    ($this->asRole)(OrganizationRole::Viewer);

    Livewire::test(DealPipeline::class)
        ->call('mountAction', 'create')
        ->assertActionNotMounted()
        ->call('callMountedAction');

    expect(Deal::query()->count())->toBe(1);
});
