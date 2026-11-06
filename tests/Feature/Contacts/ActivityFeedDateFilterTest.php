<?php

use App\Enums\ActivityType;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 03:00:00', 'UTC'));

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);

    $this->logAt = function (Contact $contact, string $subject, string $utc): Activity {
        return Activity::factory()->create([
            'organization_id' => $contact->organization_id,
            'contact_id' => $contact->id,
            'user_id' => $this->owner->id,
            'type' => ActivityType::Call,
            'subject' => $subject,
            'occurred_at' => $utc,
        ]);
    };

    $this->logWeek = function (Contact $contact): void {
        ($this->logAt)($contact, 'Late Sunday call', '2026-03-01 14:30:00');
        ($this->logAt)($contact, 'Monday breakfast', '2026-03-01 23:30:00');
        ($this->logAt)($contact, 'Tuesday midnight', '2026-03-02 15:30:00');
    };

    $this->inZone = function (string $timezone): void {
        $this->org->update(['timezone' => $timezone]);
        Filament::setTenant($this->org->refresh());
    };

    $this->feed = fn (?string $from, ?string $to, ?Contact $contact = null) => Livewire::test(ContactActivityFeed::class, ['record' => $contact ?? $this->contact])
        ->set('dateFrom', $from)
        ->set('dateTo', $to);

    $this->subjects = ['Late Sunday call', 'Monday breakfast', 'Tuesday midnight'];
});

test('the dates are local days in Asia/Tokyo', function (?string $from, ?string $to, array $listed) {
    ($this->logWeek)($this->contact);
    ($this->inZone)('Asia/Tokyo');

    $feed = ($this->feed)($from, $to);

    foreach ($this->subjects as $subject) {
        in_array($subject, $listed, true) ? $feed->assertSee($subject) : $feed->assertDontSee($subject);
    }

    if ($listed === []) {
        $feed->assertSee('No activities in this date range');
    }
})->with([
    'one local day' => ['2026-03-02', '2026-03-02', ['Monday breakfast']],
    'from only' => ['2026-03-02', null, ['Monday breakfast', 'Tuesday midnight']],
    'to only' => [null, '2026-03-01', ['Late Sunday call']],
    'after everything' => ['2026-03-04', null, []],
]);

test('an organization in UTC keeps UTC days', function () {
    ($this->logWeek)($this->contact);

    ($this->feed)('2026-03-02', '2026-03-02')
        ->assertSee('Tuesday midnight')
        ->assertDontSee('Monday breakfast')
        ->assertDontSee('Late Sunday call');
});

test('the bounds of a Tokyo day are exact', function (string $utc, bool $shown) {
    ($this->inZone)('Asia/Tokyo');
    ($this->logAt)($this->contact, 'Edge', $utc);

    $feed = ($this->feed)('2026-03-02', '2026-03-02');

    $shown ? $feed->assertSee('Edge') : $feed->assertDontSee('Edge');
})->with([
    'one second before' => ['2026-03-01 14:59:59', false],
    'first second' => ['2026-03-01 15:00:00', true],
    'last second' => ['2026-03-02 14:59:59', true],
    'one second after' => ['2026-03-02 15:00:00', false],
]);

test('the 23-hour DST day in Europe/Paris', function (string $utc, bool $shown) {
    ($this->inZone)('Europe/Paris');
    ($this->logAt)($this->contact, 'Edge', $utc);

    $feed = ($this->feed)('2026-03-29', '2026-03-29');

    $shown ? $feed->assertSee('Edge') : $feed->assertDontSee('Edge');
})->with([
    'one second before' => ['2026-03-28 22:59:59', false],
    'first second' => ['2026-03-28 23:00:00', true],
    'last second' => ['2026-03-29 21:59:59', true],
    'one second after' => ['2026-03-29 22:00:00', false],
]);

test('a value that is not a real date is ignored', function (string $junk) {
    ($this->logWeek)($this->contact);
    ($this->inZone)('Asia/Tokyo');

    $feed = ($this->feed)($junk, null);

    foreach ($this->subjects as $subject) {
        $feed->assertSee($subject);
    }

    $feed->assertDontSee('Clear')->assertDontSee('No activities in this date range');

    $feed = ($this->feed)(null, $junk);

    foreach ($this->subjects as $subject) {
        $feed->assertSee($subject);
    }

    $feed->assertDontSee('Clear');
})->with([
    'text' => ['abc'],
    'not a day' => ['2026-02-30'],
    'not a month' => ['2026-13-01'],
    'sql' => ["' OR 1=1 --"],
    'with a time' => ['2026-03-02T00:00'],
    'leading space' => [' 2026-03-02'],
    'trailing newline' => ["2026-03-02\n"],
]);

test('a date outside 1900-2100 is ignored like a junk date', function (?string $from, ?string $to) {
    ($this->logWeek)($this->contact);
    ($this->inZone)('Europe/Paris');

    $feed = ($this->feed)($from, $to);

    foreach ($this->subjects as $subject) {
        $feed->assertSee($subject);
    }

    $feed->assertDontSee('Clear')->assertDontSee('From is after To');
})->with([
    'year 1 from' => ['0001-01-01', null],
    'before 1900 from' => ['1899-12-31', null],
    'after 2100 to' => [null, '2101-01-01'],
    'year 9999 to' => [null, '9999-12-31'],
    'both out of bounds' => ['0001-01-01', '9999-12-31'],
]);

test('an out-of-bounds From next to a valid To filters on To only', function () {
    ($this->logWeek)($this->contact);
    ($this->inZone)('Europe/Paris');

    ($this->feed)('0001-01-01', '2026-03-01')
        ->assertSee('Late Sunday call')
        ->assertDontSee('Monday breakfast')
        ->assertDontSee('Tuesday midnight')
        ->assertSee('Clear')
        ->assertDontSee('From is after To');
});

test('the earliest day is kept with its exact Paris LMT bounds', function () {
    ($this->inZone)('Europe/Paris');
    ($this->logAt)($this->contact, 'Fin de siecle', '1899-12-31 23:50:39');
    ($this->logAt)($this->contact, 'Too early', '1899-12-31 23:50:38');

    ($this->feed)('1900-01-01', '1900-01-01')
        ->assertSee('Fin de siecle')
        ->assertDontSee('Too early');
});

test('the latest day is kept', function () {
    ($this->inZone)('Europe/Paris');
    ($this->logAt)($this->contact, 'Last day', '2100-12-31 22:59:59');
    ($this->logAt)($this->contact, 'Too late', '2100-12-31 23:00:00');

    ($this->feed)('2100-12-31', '2100-12-31')
        ->assertSee('Last day')
        ->assertDontSee('Too late');
});

test('swapping ignores an out-of-bounds bound', function () {
    ($this->inZone)('Europe/Paris');

    ($this->feed)('2101-01-01', '2026-03-01')
        ->call('swapDateRange')
        ->assertSet('dateFrom', '2101-01-01')
        ->assertSet('dateTo', '2026-03-01')
        ->assertDontSee('From is after To');
});

test('both date inputs carry the bounds', function () {
    $html = ($this->feed)(null, null)->html();

    expect(substr_count($html, 'min="1900-01-01"'))->toBe(2)
        ->and(substr_count($html, 'max="2100-12-31"'))->toBe(2);
});

test('a real date next to a junk date still filters', function () {
    ($this->logWeek)($this->contact);
    ($this->inZone)('Asia/Tokyo');

    ($this->feed)('2026-03-02', 'abc')
        ->assertSee('Monday breakfast')
        ->assertSee('Tuesday midnight')
        ->assertDontSee('Late Sunday call')
        ->assertSee('Clear');
});

test('from after to lists nothing', function () {
    ($this->logWeek)($this->contact);

    ($this->feed)('2026-03-03', '2026-03-01')
        ->assertSee('No activities in this date range')
        ->assertDontSee('Tuesday midnight');
});

test('a reversed range shows the hint with a swap link and keeps the empty state', function () {
    ($this->feed)('2026-02-13', '2026-02-12')
        ->assertSee('From is after To — no day can match.')
        ->assertSee('Swap them')
        ->assertSeeHtml('wire:click="swapDateRange"')
        ->assertSee('No activities in this date range');
});

test('no hint unless both bounds are valid days and From is after To', function (?string $from, ?string $to) {
    ($this->feed)($from, $to)->assertDontSee('From is after To');
})->with([
    'same day' => ['2026-02-12', '2026-02-12'],
    'only from' => ['2026-02-12', null],
    'only to' => [null, '2026-02-12'],
    'ordered' => ['2026-02-12', '2026-02-13'],
    'junk to' => ['2026-02-13', 'not-a-date'],
    'impossible from' => ['2026-02-30', '2026-02-12'],
]);

test('swapping a reversed range exchanges the bounds and lists those days', function () {
    ($this->logWeek)($this->contact);
    ($this->logAt)($this->contact, 'Later call', '2026-03-03 10:00:00');

    ($this->feed)('2026-03-02', '2026-03-01')
        ->assertDontSee('Monday breakfast')
        ->call('swapDateRange')
        ->assertSet('dateFrom', '2026-03-01')
        ->assertSet('dateTo', '2026-03-02')
        ->assertDontSee('From is after To')
        ->assertSee('Late Sunday call')
        ->assertSee('Monday breakfast')
        ->assertSee('Tuesday midnight')
        ->assertDontSee('Later call');
});

test('swapping does nothing on an ordered or half-set range', function (?string $from, ?string $to) {
    ($this->feed)($from, $to)
        ->call('swapDateRange')
        ->assertSet('dateFrom', $from)
        ->assertSet('dateTo', $to);
})->with([
    'ordered' => ['2026-03-01', '2026-03-02'],
    'only from' => ['2026-03-02', null],
    'junk to' => ['2026-03-02', 'not-a-date'],
]);

test('swapping a range with a junk bound leaves the raw values alone', function () {
    ($this->feed)('not-a-date', '2026-03-01')
        ->call('swapDateRange')
        ->assertSet('dateFrom', 'not-a-date')
        ->assertSet('dateTo', '2026-03-01')
        ->assertDontSee('From is after To');
});

test('an array is refused by Livewire with a 419 when debug is off', function () {
    config(['app.debug' => false]);

    Livewire::test(ContactActivityFeed::class, ['record' => $this->contact])
        ->set('dateFrom', ['2026-03-02'])
        ->assertStatus(419);
});

test('the timezone of one organization does not leak to another', function () {
    ($this->logWeek)($this->contact);
    ($this->inZone)('Asia/Tokyo');

    $gina = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Gina Globex']);
    $globex = $gina->personalOrganization();
    $globexContact = Contact::factory()->create(['organization_id' => $globex->id, 'name' => 'Globex Contact']);
    ($this->logWeek)($globexContact);

    ($this->feed)('2026-03-02', '2026-03-02')
        ->assertSee('Monday breakfast')
        ->assertDontSee('Tuesday midnight');

    $globex->update(['timezone' => 'UTC']);
    Filament::setTenant($globex->refresh());
    $this->actingAs($gina);

    ($this->feed)('2026-03-02', '2026-03-02', $globexContact)
        ->assertSee('Tuesday midnight')
        ->assertDontSee('Monday breakfast');
});
