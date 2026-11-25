<?php

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-11-25 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->vip = Tag::factory()->for($this->org)->create(['name' => 'VIP']);
    $this->partner = Tag::factory()->for($this->org)->create(['name' => 'Partner']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Creates one tagged contact with a company and a deal per number, each using every relation the list pages and the board show.
 */
function guardedRows(int $from, int $to): void
{
    $owner = test()->user;
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $company = Company::factory()->create([
            'organization_id' => $organization->id,
            'name' => "Guard Co {$i}",
        ]);

        $contact = Contact::factory()->withTags([test()->vip->id, test()->partner->id])->create([
            'organization_id' => $organization->id,
            'name' => "Guard Contact {$i}",
            'email' => "guard{$i}@example.test",
            'owner_id' => $owner->id,
        ]);
        $contact->companies()->attach($company);

        Deal::factory()->inStage($i % 2 === 0 ? 'Discovery' : 'Lead')->create([
            'organization_id' => $organization->id,
            'title' => "Guard Deal {$i}",
            'contact_id' => $contact->id,
            'company_id' => $company->id,
            'owner_id' => $owner->id,
            'created_by' => $owner->id,
            'value' => 1000 + $i,
            'expected_close_date' => '2026-11-27',
        ]);
    }
}

test('a page runs the same number of queries with 2 and 9 records', function (Closure $url, string $lastRecord) {
    guardedRows(1, 2);
    $url = $url();

    $this->get($url)->assertSuccessful();

    $small = queryCountOf(fn () => $this->get($url)->assertSuccessful());

    guardedRows(3, 9);

    $large = queryCountOf(fn () => $this->get($url)->assertSuccessful()->assertSee($lastRecord));

    expect($small)->toBeGreaterThan(0)
        ->and($large)->toBe($small);
})->with([
    'contacts list' => [fn (): string => ContactResource::getUrl('index'), 'Guard Contact 9'],
    'deals list' => [fn (): string => DealResource::getUrl('index'), 'Guard Deal 9'],
    'deal board' => [fn (): string => DealResource::getUrl('pipeline'), 'Guard Deal 9'],
]);

test('the counting helper does not leave the query log on', function () {
    expect(queryCountOf(fn () => Contact::query()->count()))->toBe(1)
        ->and(DB::logging())->toBeFalse()
        ->and(DB::getQueryLog())->toBe([]);
});
