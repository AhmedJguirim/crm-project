<?php

use App\Enums\DealStatus;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Deal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['timezone' => 'Europe/Paris']);
    $this->actingAs($this->user);
    Filament::setTenant($this->org->refresh());

    $this->closedDeal = fn (string $title, DealStatus $status, ?string $closedAt): Deal => Deal::factory()
        ->inStage(match ($status) {
            DealStatus::Open => 'Lead',
            DealStatus::Won => 'Won',
            DealStatus::Lost => 'Lost',
        })
        ->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->user->id,
            'title' => $title,
            'status' => $status,
            'won_at' => $status === DealStatus::Won ? $closedAt : null,
            'lost_at' => $status === DealStatus::Lost ? $closedAt : null,
        ]);

    $this->wonEarly = ($this->closedDeal)('Won early', DealStatus::Won, '2026-09-30 22:00:00');
    $this->wonLate = ($this->closedDeal)('Won late', DealStatus::Won, '2026-10-31 22:59:59');
    $this->wonBefore = ($this->closedDeal)('Won before', DealStatus::Won, '2026-09-30 21:59:59');
    $this->wonAfter = ($this->closedDeal)('Won after', DealStatus::Won, '2026-10-31 23:00:00');
    $this->lostInside = ($this->closedDeal)('Lost inside', DealStatus::Lost, '2026-10-12 10:00:00');
    $this->lostBefore = ($this->closedDeal)('Lost before', DealStatus::Lost, '2026-09-20 10:00:00');
    $this->stillOpen = ($this->closedDeal)('Still open', DealStatus::Open, null);

    $this->all = [$this->wonEarly, $this->wonLate, $this->wonBefore, $this->wonAfter, $this->lostInside, $this->lostBefore, $this->stillOpen];

    $this->closedList = fn (array $closedBetween, array $extra = []) => Livewire::withQueryParams(['filters' => ['closed_between' => $closedBetween] + $extra])->test(ListDeals::class);

    $this->closedIndicators = fn ($page): array => collect($page->instance()->getTable()->getFilter('closed_between')->getIndicators())
        ->map(fn ($indicator): string => (string) $indicator->getLabel())
        ->all();
});

afterEach(function () {
    Filament::setTenant(null);
});

test('a full range keeps the deals won or lost in those local days', function () {
    ($this->closedList)(['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertCanSeeTableRecords([$this->wonEarly, $this->wonLate, $this->lostInside])
        ->assertCanNotSeeTableRecords([$this->wonBefore, $this->wonAfter, $this->lostBefore, $this->stillOpen])
        ->assertCountTableRecords(3);
});

test('only a start date', function () {
    ($this->closedList)(['from' => '2026-10-01'])
        ->assertCanSeeTableRecords([$this->wonEarly, $this->wonLate, $this->wonAfter, $this->lostInside])
        ->assertCanNotSeeTableRecords([$this->wonBefore, $this->lostBefore, $this->stillOpen]);
});

test('only an end date', function () {
    ($this->closedList)(['until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$this->wonBefore, $this->lostBefore])
        ->assertCanNotSeeTableRecords([$this->wonEarly, $this->wonLate, $this->wonAfter, $this->lostInside, $this->stillOpen]);
});

test('with the status filter the range applies to that status only', function () {
    ($this->closedList)(['from' => '2026-10-01', 'until' => '2026-10-31'], ['status' => ['value' => DealStatus::Lost->value]])
        ->assertCanSeeTableRecords([$this->lostInside])
        ->assertCountTableRecords(1);
});

test('the indicators name the bounds and warn about a reversed range', function () {
    $page = ($this->closedList)(['from' => '2026-10-31', 'until' => '2026-10-01']);

    expect(($this->closedIndicators)($page))->toBe(['Closed from Oct 31, 2026', 'Closed until Oct 1, 2026', 'From is after Until — no deal can match']);

    $page->assertCanNotSeeTableRecords($this->all)->assertCountTableRecords(0);
});

test('each indicator removes only its own bound', function () {
    $page = ($this->closedList)(['from' => '2026-10-01', 'until' => '2026-10-31']);

    $removed = collect($page->instance()->getTable()->getFilter('closed_between')->getIndicators())
        ->map(fn ($indicator): ?string => $indicator->getRemoveField())
        ->all();

    expect($removed)->toBe(['from', 'until']);
});

test('a junk or out-of-range date is ignored', function (string $from) {
    $page = ($this->closedList)(['from' => $from])
        ->assertCanSeeTableRecords($this->all)
        ->assertCountTableRecords(7);

    expect(($this->closedIndicators)($page))->toBe([]);
})->with(['junk' => 'abc', 'impossible' => '2026-02-30', 'before 1900' => '1899-12-31', 'after 2100' => '2101-01-01']);

test('an out-of-range end date is ignored too', function () {
    $page = ($this->closedList)(['until' => '1899-12-31'])->assertCountTableRecords(7);

    expect(($this->closedIndicators)($page))->toBe([]);
});

test('another organization closed deals never show', function () {
    $globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = collect([DealStatus::Won, DealStatus::Lost])->map(fn (DealStatus $status): Deal => Deal::factory()->inStage($status === DealStatus::Won ? 'Won' : 'Lost')->create([
        'organization_id' => $globex->id,
        'title' => 'Globex '.$status->value,
        'status' => $status,
        'won_at' => $status === DealStatus::Won ? '2026-10-10 10:00:00' : null,
        'lost_at' => $status === DealStatus::Lost ? '2026-10-10 10:00:00' : null,
    ]))->all();

    ($this->closedList)(['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertCanSeeTableRecords([$this->wonEarly, $this->wonLate, $this->lostInside])
        ->assertCanNotSeeTableRecords($foreign)
        ->assertCountTableRecords(3);
});

test('the scope keeps the or inside its own group', function () {
    $sql = Deal::query()->closedBetween('2026-10-01', '2026-10-31')->toRawSql();

    expect(Deal::query()->closedBetween(null, null)->toSql())->toBe(Deal::query()->toSql())
        ->and(Deal::query()->where('title', 'Won early')->closedBetween('2026-10-01', '2026-10-31')->count())->toBe(1)
        ->and(Deal::query()->where('title', 'Still open')->closedBetween('2026-10-01', '2026-10-31')->count())->toBe(0)
        ->and($sql)->toContain('"status" = \'won\'')->toContain('"status" = \'lost\'');
});
