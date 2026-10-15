<?php

use App\Enums\DealStatus;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Models\Segment;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-08 09:15:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

function stageMoverStage(User $user, string $stage): PipelineStage
{
    return pipelineStageNamed($user->personalOrganization()->id, $stage);
}

function stageMoverDeal(User $user, string $stage, ?string $wonAt = null, ?string $lostAt = null, ?Contact $contact = null): Deal
{
    $status = match ($stage) {
        'Won' => DealStatus::Won,
        'Lost' => DealStatus::Lost,
        default => DealStatus::Open,
    };

    return Deal::factory()->inStage($stage)->create([
        'organization_id' => $user->personalOrganization()->id,
        'created_by' => $user->id,
        'contact_id' => $contact?->id,
        'status' => $status,
        'won_at' => $wonAt,
        'lost_at' => $lostAt,
        'position' => '1000.0000000000',
    ]);
}

test('attributesFor sets the status and the dates of a new deal from the stage', function (string $stage, DealStatus $status, ?string $wonAt, ?string $lostAt) {
    $pipelineStage = stageMoverStage($this->user, $stage);
    $attributes = DealStageMover::attributesFor($pipelineStage);

    expect(array_keys($attributes))->toBe(['pipeline_stage_id', 'status', 'won_at', 'lost_at'])
        ->and($attributes['pipeline_stage_id'])->toBe($pipelineStage->id)
        ->and($attributes['status'])->toBe($status)
        ->and($attributes['won_at']?->toDateTimeString())->toBe($wonAt)
        ->and($attributes['lost_at']?->toDateTimeString())->toBe($lostAt);
})->with([
    'lead' => ['Lead', DealStatus::Open, null, null],
    'discovery' => ['Discovery', DealStatus::Open, null, null],
    'proposal sent' => ['Proposal Sent', DealStatus::Open, null, null],
    'negotiating' => ['Negotiating', DealStatus::Open, null, null],
    'won' => ['Won', DealStatus::Won, '2026-10-08 09:15:00', null],
    'lost' => ['Lost', DealStatus::Lost, null, '2026-10-08 09:15:00'],
]);

test('attributesFor takes the status from the type of the pipeline stage, not from its name', function () {
    $renamed = stageMoverStage($this->user, 'Won');
    $renamed->update(['name' => 'Closed deal']);

    expect(DealStageMover::attributesFor($renamed->fresh())['status'])->toBe(DealStatus::Won);
});

test('attributesFor keeps or resets the dates of an existing deal', function (string $from, ?string $wonBefore, ?string $lostBefore, string $to, DealStatus $status, ?string $wonAfter, ?string $lostAfter) {
    $deal = stageMoverDeal($this->user, $from, $wonBefore, $lostBefore);

    $attributes = DealStageMover::attributesFor(stageMoverStage($this->user, $to), $deal);

    expect($attributes['status'])->toBe($status)
        ->and($attributes['won_at']?->toDateTimeString())->toBe($wonAfter)
        ->and($attributes['lost_at']?->toDateTimeString())->toBe($lostAfter);
})->with([
    'won stays won' => ['Won', '2026-08-28 14:30:00', null, 'Won', DealStatus::Won, '2026-08-28 14:30:00', null],
    'lost stays lost' => ['Lost', null, '2026-09-01 09:00:00', 'Lost', DealStatus::Lost, null, '2026-09-01 09:00:00'],
    'won to lost' => ['Won', '2026-08-28 14:30:00', null, 'Lost', DealStatus::Lost, null, '2026-10-08 09:15:00'],
    'lost to won' => ['Lost', null, '2026-09-01 09:00:00', 'Won', DealStatus::Won, '2026-10-08 09:15:00', null],
    'won to an open stage' => ['Won', '2026-08-28 14:30:00', null, 'Negotiating', DealStatus::Open, null, null],
    'open to won' => ['Negotiating', null, null, 'Won', DealStatus::Won, '2026-10-08 09:15:00', null],
    'won without a date stays won' => ['Won', null, null, 'Won', DealStatus::Won, '2026-10-08 09:15:00', null],
]);

test('move saves the deal through model events', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Alice Smith']);
    Segment::factory()->for($this->org)->published()->create();
    $deal = stageMoverDeal($this->user, 'Negotiating', contact: $contact);
    Queue::fake([ResyncContactSegments::class]);

    $moved = DealStageMover::move($deal, stageMoverStage($this->user, 'Won'));

    $stored = Deal::query()->findOrFail($deal->id);

    expect($moved->is($deal))->toBeTrue()
        ->and($stored->pipelineStage->name)->toBe('Won')
        ->and($stored->status)->toBe(DealStatus::Won)
        ->and($stored->won_at->toDateTimeString())->toBe('2026-10-08 09:15:00')
        ->and($stored->lost_at)->toBeNull();

    Queue::assertPushed(ResyncContactSegments::class, fn (ResyncContactSegments $job): bool => $job->contactId === $contact->id);
});

test('the create page sets the status and the dates from the stage', function (string $stage, DealStatus $status, ?string $wonAt, ?string $lostAt) {
    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Retainer', 'pipeline_stage_id' => stageMoverStage($this->user, $stage)->id])
        ->call('create')
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'Retainer')->firstOrFail();

    expect($deal->pipeline_stage_id)->toBe(stageMoverStage($this->user, $stage)->id)
        ->and($deal->pipelineStage->name)->toBe($stage)
        ->and($deal->status)->toBe($status)
        ->and($deal->won_at?->toDateTimeString())->toBe($wonAt)
        ->and($deal->lost_at?->toDateTimeString())->toBe($lostAt);
})->with([
    'negotiating' => ['Negotiating', DealStatus::Open, null, null],
    'won' => ['Won', DealStatus::Won, '2026-10-08 09:15:00', null],
    'lost' => ['Lost', DealStatus::Lost, null, '2026-10-08 09:15:00'],
]);

test('reordering a won card inside the Won column keeps its won date', function () {
    $deal = stageMoverDeal($this->user, 'Won', wonAt: '2026-08-28 14:30:00');

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $deal->id, (string) pipelineStageNamed($this->org->id, 'Won')->id);

    $deal->refresh();

    expect($deal->status)->toBe(DealStatus::Won)
        ->and($deal->won_at->toDateTimeString())->toBe('2026-08-28 14:30:00');
});

test('dragging a won card to Lost moves its date from won to lost', function () {
    $deal = stageMoverDeal($this->user, 'Won', wonAt: '2026-08-28 14:30:00');

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $deal->id, (string) pipelineStageNamed($this->org->id, 'Lost')->id);

    $deal->refresh();

    expect($deal->pipelineStage->name)->toBe('Lost')
        ->and($deal->pipeline_stage_id)->toBe(stageMoverStage($this->user, 'Lost')->id)
        ->and($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->won_at)->toBeNull()
        ->and($deal->lost_at->toDateTimeString())->toBe('2026-10-08 09:15:00');
});

test('the board writes the stage and the status in one transaction', function () {
    $deal = stageMoverDeal($this->user, 'Negotiating');

    $armed = true;

    Deal::saving(function (Deal $saving) use (&$armed): void {
        if ($armed && $saving->status === DealStatus::Won) {
            throw new RuntimeException('Boom');
        }
    });

    try {
        expect(fn () => Livewire::test(DealPipeline::class)
            ->call('moveCard', (string) $deal->id, (string) pipelineStageNamed($this->org->id, 'Won')->id))->toThrow(RuntimeException::class, 'Boom');
    } finally {
        $armed = false;
    }

    $deal->refresh();

    expect($deal->pipelineStage->name)->toBe('Negotiating')
        ->and($deal->status)->toBe(DealStatus::Open);
});

test('the deal page actions stamp the current time', function (string $action, string $stage, DealStatus $status, ?string $wonAt, ?string $lostAt, string $title) {
    $deal = stageMoverDeal($this->user, 'Negotiating');

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction($action)
        ->assertHasNoActionErrors()
        ->assertNotified($title);

    $deal->refresh();

    expect($deal->pipelineStage->name)->toBe($stage)
        ->and($deal->pipeline_stage_id)->toBe(stageMoverStage($this->user, $stage)->id)
        ->and($deal->status)->toBe($status)
        ->and($deal->won_at?->toDateTimeString())->toBe($wonAt)
        ->and($deal->lost_at?->toDateTimeString())->toBe($lostAt);
})->with([
    'move to won' => ['moveToWon', 'Won', DealStatus::Won, '2026-10-08 09:15:00', null, 'Deal moved to Won'],
    'move to lost' => ['moveToLost', 'Lost', DealStatus::Lost, null, '2026-10-08 09:15:00', 'Deal moved to Lost'],
]);

dataset('inline deal create forms', [
    'view deal log activity' => [
        fn () => Livewire::test(ViewDeal::class, ['record' => stageMoverDeal(test()->user, 'Lead', contact: test()->contact)->id]),
        fn (): array => [TestAction::make('logActivity'), TestAction::make('createOption')->schemaComponent('deal_id')],
    ],
    'view contact log activity' => [
        fn () => Livewire::test(ViewContact::class, ['record' => test()->contact->id]),
        fn (): array => [TestAction::make('logActivity'), TestAction::make('createOption')->schemaComponent('deal_id')],
    ],
    'contacts table log activity' => [
        fn () => Livewire::test(ListContacts::class),
        fn (): array => [TestAction::make('logActivity')->table(test()->contact), TestAction::make('createOption')->schemaComponent('deal_id')],
    ],
    'quick task on a contact' => [
        fn () => Livewire::test(ViewContact::class, ['record' => test()->contact->id])->mountAction('quickTask'),
        fn (): TestAction => TestAction::make('createOption')->schemaComponent('deal_id'),
    ],
    'create task page' => [
        fn () => Livewire::test(CreateTask::class)->fillForm(['contact_id' => test()->contact->id]),
        fn (): TestAction => TestAction::make('createOption')->schemaComponent('deal_id'),
    ],
]);

test('every inline create deal form sets the creator as the deal owner', function (Closure $page, Closure $actions) {
    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $page()
        ->callAction($actions(), data: [
            'title' => 'Inline deal',
            'pipeline_stage_id' => stageMoverStage($this->user, 'Lead')->id,
        ])
        ->assertHasNoActionErrors();

    $deal = Deal::query()->where('title', 'Inline deal')->firstOrFail();

    expect($deal->owner_id)->toBe($this->user->id)
        ->and($deal->created_by)->toBe($this->user->id);
})->with('inline deal create forms');

test('every inline create deal form sets the status and the dates from the stage', function (Closure $page, Closure $actions, string $stage, DealStatus $status, ?string $wonAt, ?string $lostAt) {
    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $page()
        ->callAction($actions(), data: [
            'title' => 'Inline deal',
            'pipeline_stage_id' => stageMoverStage($this->user, $stage)->id,
        ])
        ->assertHasNoActionErrors();

    $deal = Deal::query()->where('title', 'Inline deal')->firstOrFail();

    expect($deal->pipeline_stage_id)->toBe(stageMoverStage($this->user, $stage)->id)
        ->and($deal->pipelineStage->name)->toBe($stage)
        ->and($deal->status)->toBe($status)
        ->and($deal->won_at?->toDateTimeString())->toBe($wonAt)
        ->and($deal->lost_at?->toDateTimeString())->toBe($lostAt);
})->with('inline deal create forms')->with([
    'lead' => ['Lead', DealStatus::Open, null, null],
    'won' => ['Won', DealStatus::Won, '2026-10-08 09:15:00', null],
    'lost' => ['Lost', DealStatus::Lost, null, '2026-10-08 09:15:00'],
]);

test('the stage select defaults to the first open stage and lists the pipeline in order', function () {
    $lead = stageMoverStage($this->user, 'Lead');

    Livewire::test(CreateDeal::class)
        ->assertFormSet(['pipeline_stage_id' => $lead->id])
        ->assertFormFieldExists('pipeline_stage_id', fn ($field): bool => array_values($field->getOptions()) === ['Lead', 'Discovery', 'Proposal Sent', 'Negotiating', 'Won', 'Lost']);
});

test('the create form refuses the stage id of another organization', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreignWon = pipelineStageNamed($other->id, 'Won');

    $page = Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Forged stage', 'pipeline_stage_id' => $foreignWon->id])
        ->call('create');

    expect($page->errors()->has('data.pipeline_stage_id'))->toBeTrue()
        ->and(Deal::query()->where('title', 'Forged stage')->exists())->toBeFalse();
});

test('the edit form refuses the stage id of another organization and keeps the deal', function () {
    $other = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $deal = stageMoverDeal($this->user, 'Lead');

    $page = Livewire::test(EditDeal::class, ['record' => $deal->id])
        ->fillForm(['pipeline_stage_id' => pipelineStageNamed($other->id, 'Won')->id])
        ->call('save');

    expect($page->errors()->has('data.pipeline_stage_id'))->toBeTrue()
        ->and($deal->fresh()->pipeline_stage_id)->toBe(stageMoverStage($this->user, 'Lead')->id);
});
