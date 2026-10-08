<?php

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Tasks\Pages\CreateTask;
use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use App\Models\Deal;
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

function stageMoverDeal(User $user, DealStage $stage, ?string $wonAt = null, ?string $lostAt = null, ?Contact $contact = null): Deal
{
    $status = match ($stage) {
        DealStage::Won => DealStatus::Won,
        DealStage::Lost => DealStatus::Lost,
        default => DealStatus::Open,
    };

    return Deal::factory()->create([
        'organization_id' => $user->personalOrganization()->id,
        'created_by' => $user->id,
        'contact_id' => $contact?->id,
        'stage' => $stage,
        'status' => $status,
        'won_at' => $wonAt,
        'lost_at' => $lostAt,
        'position' => '1000.0000000000',
    ]);
}

test('attributesFor sets the status and the dates of a new deal from the stage', function (DealStage $stage, DealStatus $status, ?string $wonAt, ?string $lostAt) {
    $attributes = DealStageMover::attributesFor($stage);

    expect(array_keys($attributes))->toBe(['stage', 'status', 'won_at', 'lost_at'])
        ->and($attributes['stage'])->toBe($stage)
        ->and($attributes['status'])->toBe($status)
        ->and($attributes['won_at']?->toDateTimeString())->toBe($wonAt)
        ->and($attributes['lost_at']?->toDateTimeString())->toBe($lostAt);
})->with([
    'lead' => [DealStage::Lead, DealStatus::Open, null, null],
    'discovery' => [DealStage::Discovery, DealStatus::Open, null, null],
    'proposal sent' => [DealStage::ProposalSent, DealStatus::Open, null, null],
    'negotiating' => [DealStage::Negotiating, DealStatus::Open, null, null],
    'won' => [DealStage::Won, DealStatus::Won, '2026-10-08 09:15:00', null],
    'lost' => [DealStage::Lost, DealStatus::Lost, null, '2026-10-08 09:15:00'],
]);

test('attributesFor accepts a stage given as a string', function () {
    $attributes = DealStageMover::attributesFor('won');

    expect($attributes['stage'])->toBe(DealStage::Won)
        ->and($attributes['status'])->toBe(DealStatus::Won);
});

test('attributesFor throws on an unknown stage', function () {
    DealStageMover::attributesFor('closed');
})->throws(ValueError::class);

test('attributesFor keeps or resets the dates of an existing deal', function (DealStage $from, ?string $wonBefore, ?string $lostBefore, DealStage $to, DealStatus $status, ?string $wonAfter, ?string $lostAfter) {
    $deal = stageMoverDeal($this->user, $from, $wonBefore, $lostBefore);

    $attributes = DealStageMover::attributesFor($to, $deal);

    expect($attributes['status'])->toBe($status)
        ->and($attributes['won_at']?->toDateTimeString())->toBe($wonAfter)
        ->and($attributes['lost_at']?->toDateTimeString())->toBe($lostAfter);
})->with([
    'won stays won' => [DealStage::Won, '2026-08-28 14:30:00', null, DealStage::Won, DealStatus::Won, '2026-08-28 14:30:00', null],
    'lost stays lost' => [DealStage::Lost, null, '2026-09-01 09:00:00', DealStage::Lost, DealStatus::Lost, null, '2026-09-01 09:00:00'],
    'won to lost' => [DealStage::Won, '2026-08-28 14:30:00', null, DealStage::Lost, DealStatus::Lost, null, '2026-10-08 09:15:00'],
    'lost to won' => [DealStage::Lost, null, '2026-09-01 09:00:00', DealStage::Won, DealStatus::Won, '2026-10-08 09:15:00', null],
    'won to an open stage' => [DealStage::Won, '2026-08-28 14:30:00', null, DealStage::Negotiating, DealStatus::Open, null, null],
    'open to won' => [DealStage::Negotiating, null, null, DealStage::Won, DealStatus::Won, '2026-10-08 09:15:00', null],
    'won without a date stays won' => [DealStage::Won, null, null, DealStage::Won, DealStatus::Won, '2026-10-08 09:15:00', null],
]);

test('move saves the deal through model events', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Alice Smith']);
    Segment::factory()->for($this->org)->published()->create();
    $deal = stageMoverDeal($this->user, DealStage::Negotiating, contact: $contact);
    Queue::fake([ResyncContactSegments::class]);

    $moved = DealStageMover::move($deal, DealStage::Won);

    $stored = Deal::query()->findOrFail($deal->id);

    expect($moved->is($deal))->toBeTrue()
        ->and($stored->stage)->toBe(DealStage::Won)
        ->and($stored->status)->toBe(DealStatus::Won)
        ->and($stored->won_at->toDateTimeString())->toBe('2026-10-08 09:15:00')
        ->and($stored->lost_at)->toBeNull();

    Queue::assertPushed(ResyncContactSegments::class, fn (ResyncContactSegments $job): bool => $job->contactId === $contact->id);
});

test('the create page sets the status and the dates from the stage', function (DealStage $stage, DealStatus $status, ?string $wonAt, ?string $lostAt) {
    Livewire::test(CreateDeal::class)
        ->fillForm(['title' => 'Retainer', 'stage' => $stage])
        ->call('create')
        ->assertHasNoFormErrors();

    $deal = Deal::query()->where('title', 'Retainer')->firstOrFail();

    expect($deal->status)->toBe($status)
        ->and($deal->won_at?->toDateTimeString())->toBe($wonAt)
        ->and($deal->lost_at?->toDateTimeString())->toBe($lostAt);
})->with([
    'negotiating' => [DealStage::Negotiating, DealStatus::Open, null, null],
    'won' => [DealStage::Won, DealStatus::Won, '2026-10-08 09:15:00', null],
    'lost' => [DealStage::Lost, DealStatus::Lost, null, '2026-10-08 09:15:00'],
]);

test('reordering a won card inside the Won column keeps its won date', function () {
    $deal = stageMoverDeal($this->user, DealStage::Won, wonAt: '2026-08-28 14:30:00');

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $deal->id, DealStage::Won->value);

    $deal->refresh();

    expect($deal->status)->toBe(DealStatus::Won)
        ->and($deal->won_at->toDateTimeString())->toBe('2026-08-28 14:30:00');
});

test('dragging a won card to Lost moves its date from won to lost', function () {
    $deal = stageMoverDeal($this->user, DealStage::Won, wonAt: '2026-08-28 14:30:00');

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $deal->id, DealStage::Lost->value);

    $deal->refresh();

    expect($deal->stage)->toBe(DealStage::Lost)
        ->and($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->won_at)->toBeNull()
        ->and($deal->lost_at->toDateTimeString())->toBe('2026-10-08 09:15:00');
});

test('the board writes the stage and the status in one transaction', function () {
    $deal = stageMoverDeal($this->user, DealStage::Negotiating);

    $armed = true;

    Deal::saving(function (Deal $saving) use (&$armed): void {
        if ($armed && $saving->status === DealStatus::Won) {
            throw new RuntimeException('Boom');
        }
    });

    try {
        expect(fn () => Livewire::test(DealPipeline::class)
            ->call('moveCard', (string) $deal->id, DealStage::Won->value))->toThrow(RuntimeException::class, 'Boom');
    } finally {
        $armed = false;
    }

    $deal->refresh();

    expect($deal->stage)->toBe(DealStage::Negotiating)
        ->and($deal->status)->toBe(DealStatus::Open);
});

test('the deal page actions stamp the current time', function (string $action, DealStage $stage, DealStatus $status, ?string $wonAt, ?string $lostAt, string $title) {
    $deal = stageMoverDeal($this->user, DealStage::Negotiating);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction($action)
        ->assertHasNoActionErrors()
        ->assertNotified($title);

    $deal->refresh();

    expect($deal->stage)->toBe($stage)
        ->and($deal->status)->toBe($status)
        ->and($deal->won_at?->toDateTimeString())->toBe($wonAt)
        ->and($deal->lost_at?->toDateTimeString())->toBe($lostAt);
})->with([
    'move to won' => ['moveToWon', DealStage::Won, DealStatus::Won, '2026-10-08 09:15:00', null, 'Deal moved to Won'],
    'move to lost' => ['moveToLost', DealStage::Lost, DealStatus::Lost, null, '2026-10-08 09:15:00', 'Deal moved to Lost'],
]);

dataset('inline deal create forms', [
    'view deal log activity' => [
        fn () => Livewire::test(ViewDeal::class, ['record' => stageMoverDeal(test()->user, DealStage::Lead, contact: test()->contact)->id]),
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

test('every inline create deal form sets the status and the dates from the stage', function (Closure $page, Closure $actions, DealStage $stage, DealStatus $status, ?string $wonAt, ?string $lostAt) {
    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $page()
        ->callAction($actions(), data: [
            'title' => 'Inline deal',
            'stage' => $stage,
        ])
        ->assertHasNoActionErrors();

    $deal = Deal::query()->where('title', 'Inline deal')->firstOrFail();

    expect($deal->stage)->toBe($stage)
        ->and($deal->status)->toBe($status)
        ->and($deal->won_at?->toDateTimeString())->toBe($wonAt)
        ->and($deal->lost_at?->toDateTimeString())->toBe($lostAt);
})->with('inline deal create forms')->with([
    'lead' => [DealStage::Lead, DealStatus::Open, null, null],
    'won' => [DealStage::Won, DealStatus::Won, '2026-10-08 09:15:00', null],
    'lost' => [DealStage::Lost, DealStatus::Lost, null, '2026-10-08 09:15:00'],
]);
