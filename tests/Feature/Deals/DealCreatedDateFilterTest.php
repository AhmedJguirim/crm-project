<?php

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

    $this->createdDeal = fn (string $title, string $createdAt): Deal => Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => $title,
        'created_at' => $createdAt,
    ]);

    $this->createdEarly = ($this->createdDeal)('Created early', '2026-09-30 22:00:00');
    $this->createdLate = ($this->createdDeal)('Created late', '2026-10-31 22:59:59');
    $this->createdBefore = ($this->createdDeal)('Created before', '2026-09-30 21:59:59');
    $this->createdAfter = ($this->createdDeal)('Created after', '2026-10-31 23:00:00');
    $this->createdMid = ($this->createdDeal)('Created mid', '2026-10-12 10:00:00');

    $this->all = [$this->createdEarly, $this->createdLate, $this->createdBefore, $this->createdAfter, $this->createdMid];

    $this->createdList = fn (array $createdBetween) => Livewire::withQueryParams(['filters' => ['created_between' => $createdBetween]])->test(ListDeals::class);

    $this->createdIndicators = fn ($page): array => collect($page->instance()->getTable()->getFilter('created_between')->getIndicators())
        ->map(fn ($indicator): string => (string) $indicator->getLabel())
        ->all();
});

afterEach(function () {
    Filament::setTenant(null);
});

test('a full range keeps the deals created in those local days', function () {
    ($this->createdList)(['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertCanSeeTableRecords([$this->createdEarly, $this->createdMid, $this->createdLate])
        ->assertCanNotSeeTableRecords([$this->createdBefore, $this->createdAfter])
        ->assertCountTableRecords(3);
});

test('only a start date', function () {
    ($this->createdList)(['from' => '2026-10-01'])
        ->assertCanSeeTableRecords([$this->createdEarly, $this->createdMid, $this->createdLate, $this->createdAfter])
        ->assertCanNotSeeTableRecords([$this->createdBefore]);
});

test('only an end date', function () {
    ($this->createdList)(['until' => '2026-09-30'])
        ->assertCanSeeTableRecords([$this->createdBefore])
        ->assertCanNotSeeTableRecords([$this->createdEarly, $this->createdMid, $this->createdLate, $this->createdAfter])
        ->assertCountTableRecords(1);
});

test('the indicators name the bounds and warn about a reversed range', function () {
    $page = ($this->createdList)(['from' => '2026-10-31', 'until' => '2026-10-01']);

    expect(($this->createdIndicators)($page))->toBe(['Created from Oct 31, 2026', 'Created until Oct 1, 2026', 'From is after Until — no deal can match']);

    $page->assertCanNotSeeTableRecords($this->all)->assertCountTableRecords(0);
});

test('each indicator removes only its own bound', function () {
    $page = ($this->createdList)(['from' => '2026-10-01', 'until' => '2026-10-31']);

    $removed = collect($page->instance()->getTable()->getFilter('created_between')->getIndicators())
        ->map(fn ($indicator): ?string => $indicator->getRemoveField())
        ->all();

    expect($removed)->toBe(['from', 'until']);
});

test('removing the until indicator clears only that bound', function () {
    $page = ($this->createdList)(['from' => '2026-10-01', 'until' => '2026-10-31']);

    $page->call('removeTableFilter', 'created_between', 'until')
        ->assertSet('tableFilters.created_between.until', null)
        ->assertSet('tableFilters.created_between.from', '2026-10-01');
});

test('a junk or out-of-range date is ignored', function (string $from) {
    $page = ($this->createdList)(['from' => $from])
        ->assertCanSeeTableRecords($this->all)
        ->assertCountTableRecords(5);

    expect(($this->createdIndicators)($page))->toBe([]);
})->with(['junk' => 'abc', 'impossible' => '2026-02-30', 'before 1900' => '1899-12-31', 'after 2100' => '2101-01-01']);

test('an out-of-range end date is ignored too', function () {
    $page = ($this->createdList)(['until' => '1899-12-31'])->assertCountTableRecords(5);

    expect(($this->createdIndicators)($page))->toBe([]);
});

test('another organization deal never shows', function () {
    $globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = Deal::factory()->create([
        'organization_id' => $globex->id,
        'title' => 'Globex new',
        'created_at' => '2026-10-10 10:00:00',
    ]);

    ($this->createdList)(['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertCountTableRecords(3);
});
