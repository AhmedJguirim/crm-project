<?php

use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Contact;
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

    $this->createdContact = fn (string $name, string $createdAt): Contact => Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        'created_at' => $createdAt,
    ]);

    $this->createdEarly = ($this->createdContact)('Createdearly', '2026-09-30 22:00:00');
    $this->createdLate = ($this->createdContact)('Createdlate', '2026-10-31 22:59:59');
    $this->createdBefore = ($this->createdContact)('Createdbefore', '2026-09-30 21:59:59');
    $this->createdAfter = ($this->createdContact)('Createdafter', '2026-10-31 23:00:00');
    $this->trashedInside = ($this->createdContact)('Trashedinside', '2026-10-12 10:00:00');
    $this->trashedInside->delete();

    $this->createdList = fn (array $createdBetween, array $extra = []) => Livewire::withQueryParams(['filters' => ['created_between' => $createdBetween] + $extra])->test(ListContacts::class);
});

afterEach(function () {
    Filament::setTenant(null);
});

test('a full range keeps the contacts created in those local days, without trashed ones', function () {
    ($this->createdList)(['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertCanSeeTableRecords([$this->createdEarly, $this->createdLate])
        ->assertCanNotSeeTableRecords([$this->createdBefore, $this->createdAfter, $this->trashedInside])
        ->assertCountTableRecords(2);
});

test('with the trashed filter set to with trashed the range applies to trashed contacts too', function () {
    ($this->createdList)(['from' => '2026-10-01', 'until' => '2026-10-31'], ['trashed' => ['value' => '1']])
        ->assertCanSeeTableRecords([$this->createdEarly, $this->createdLate, $this->trashedInside])
        ->assertCanNotSeeTableRecords([$this->createdBefore, $this->createdAfter])
        ->assertCountTableRecords(3);
});

test('the indicators name the bounds and the reversed-range warning names contacts', function () {
    $page = ($this->createdList)(['from' => '2026-10-31', 'until' => '2026-10-01']);

    $indicators = collect($page->instance()->getTable()->getFilter('created_between')->getIndicators())
        ->map(fn ($indicator): string => (string) $indicator->getLabel())
        ->all();

    expect($indicators)->toBe(['Created from Oct 31, 2026', 'Created until Oct 1, 2026', 'From is after Until — no contact can match']);

    $page->assertCountTableRecords(0);
});

test('a junk date is ignored', function () {
    ($this->createdList)(['from' => 'abc', 'until' => '2101-01-01'])
        ->assertCanSeeTableRecords([$this->createdEarly, $this->createdLate, $this->createdBefore, $this->createdAfter])
        ->assertCountTableRecords(4);
});

test('another organization contact never shows', function () {
    $globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = Contact::factory()->create([
        'organization_id' => $globex->id,
        'name' => 'Globexnew',
        'created_at' => '2026-10-10 10:00:00',
    ]);

    ($this->createdList)(['from' => '2026-10-01', 'until' => '2026-10-31'])
        ->assertCanNotSeeTableRecords([$foreign])
        ->assertCountTableRecords(2);
});
