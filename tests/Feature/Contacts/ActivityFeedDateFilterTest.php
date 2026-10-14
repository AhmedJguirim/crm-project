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
