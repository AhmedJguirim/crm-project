<?php

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);

    $this->deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'title' => 'Retainer',
        'stage' => DealStage::Lead,
        'status' => DealStatus::Open,
        'won_at' => null,
        'lost_at' => null,
        'position' => '1000.0000000000',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('opening the drawer with a malformed key opens nothing', function (string $key) {
    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => $key]);

    expect($component->instance()->mountedActions)->toBe([]);
})->with([
    'letters' => 'abc',
    'sql fragment' => '1 OR 1=1',
    'above bigint' => '99999999999999999999',
    'bigint max plus one' => '9223372036854775808',
    'zero' => '0',
    'negative' => '-1',
    'decimal' => '1.5',
    'bigint max (valid but unknown)' => '9223372036854775807',
]);

test('a malformed key in the arguments instead of the context opens nothing', function () {
    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', ['recordKey' => 'abc'], []);

    expect($component->instance()->mountedActions)->toBe([]);
});

test('a drawer child with a malformed key changes nothing', function (string $child, string $key) {
    $before = [$this->deal->fresh()->getAttributes(), Task::query()->withoutGlobalScopes()->count(), Activity::query()->withoutGlobalScopes()->count()];

    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $this->deal->id])
        ->call('mountAction', $child, [], ['recordKey' => $key]);

    $component->call('callMountedAction', ['stage' => 'won', 'from_stage' => 'lead', 'title' => 'Hacked', 'type' => 'call', 'occurred_at' => '2026-10-09 10:00:00']);

    expect([$this->deal->fresh()->getAttributes(), Task::query()->withoutGlobalScopes()->count(), Activity::query()->withoutGlobalScopes()->count()])->toBe($before);
})->with([
    'moveStage' => ['moveStage', 'abc'],
    'editDeal' => ['editDeal', 'abc'],
    'logActivity' => ['logActivity', 'abc'],
    'addTask' => ['addTask', 'abc'],
    'moveStage above bigint' => ['moveStage', '99999999999999999999'],
    'addTask sql fragment' => ['addTask', '1 OR 1=1'],
]);

test('a child action mounted after the drawer reads no record for a malformed key', function () {
    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $this->deal->id]);

    $component->instance()->mountedActions[] = ['name' => 'moveStage', 'arguments' => [], 'context' => ['recordKey' => 'abc']];

    expect($component->instance()->getDefaultActionRecord(Action::make('moveStage')))->toBeNull();
});

test('a child action mounted after the drawer still reads the record of a valid key', function () {
    $component = Livewire::test(DealPipeline::class)
        ->call('mountAction', 'openDeal', [], ['recordKey' => (string) $this->deal->id]);

    $component->instance()->mountedActions[] = ['name' => 'moveStage', 'arguments' => [], 'context' => ['recordKey' => (string) $this->deal->id]];

    expect($component->instance()->getDefaultActionRecord(Action::make('moveStage'))?->is($this->deal))->toBeTrue();
});

test('moveCard with malformed input does nothing', function (string $cardId, string $column, ?string $after, ?string $before) {
    $cardId = $cardId === 'valid' ? (string) $this->deal->id : $cardId;
    $original = $this->deal->fresh()->getAttributes();

    Livewire::test(DealPipeline::class)->call('moveCard', $cardId, $column, $after, $before);

    expect($this->deal->fresh()->getAttributes())->toBe($original);
})->with([
    'letters card' => ['abc', 'won', null, null],
    'above bigint card' => ['99999999999999999999', 'won', null, null],
    'forged column' => ['valid', 'archived', null, null],
    'letters after' => ['valid', 'won', 'abc', null],
    'sql before' => ['valid', 'won', null, '1 OR 1=1'],
    'above bigint after' => ['valid', 'won', '9223372036854775808', null],
]);

test('moveCard still moves a card with valid neighbours', function () {
    $neighbour = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Won,
        'status' => DealStatus::Won,
        'position' => '1000.0000000000',
    ]);

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $this->deal->id, DealStage::Won->value, (string) $neighbour->id, null);

    expect($this->deal->fresh()->stage)->toBe(DealStage::Won)
        ->and($this->deal->fresh()->status)->toBe(DealStatus::Won);
});
