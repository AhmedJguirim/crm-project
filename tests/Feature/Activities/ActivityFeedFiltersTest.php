<?php

use App\Enums\ActivityType;
use App\Filament\Resources\Companies\Widgets\CompanyActivityFeed;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Deals\Widgets\DealActivityFeed;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
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
    $this->deal = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Retainer']);
    $this->company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Initech']);

    $this->feedClass = fn (string $feed): string => match ($feed) {
        'contact' => ContactActivityFeed::class,
        'deal' => DealActivityFeed::class,
        'company' => CompanyActivityFeed::class,
    };

    $this->pageName = fn (string $feed): string => $feed === 'company' ? 'activitiesPage' : 'page';

    $this->feed = fn (string $feed) => Livewire::test(($this->feedClass)($feed), [
        'record' => match ($feed) {
            'contact' => $this->contact,
            'deal' => $this->deal,
            'company' => $this->company,
        },
    ]);

    $this->logOn = function (string $feed, ActivityType $type, string $subject, string $utc): Activity {
        $factory = Activity::factory()->type($type);

        $factory = match ($feed) {
            'contact' => $factory->state(['organization_id' => $this->org->id, 'contact_id' => $this->contact->id, 'deal_id' => null, 'company_id' => null]),
            'deal' => $factory->onlyOnDeal($this->deal),
            'company' => $factory->onlyOnCompany($this->company),
        };

        return $factory->create(['user_id' => $this->owner->id, 'subject' => $subject, 'occurred_at' => $utc]);
    };

    $this->seedFour = function (string $feed): void {
        ($this->logOn)($feed, ActivityType::Call, 'Intro call', '2026-03-02 10:00:00');
        ($this->logOn)($feed, ActivityType::Meeting, 'Kickoff meeting', '2026-03-02 15:00:00');
        ($this->logOn)($feed, ActivityType::Note, 'Budget note', '2026-03-03 09:00:00');
        ($this->logOn)($feed, ActivityType::Email, 'Follow-up email', '2026-02-20 09:00:00');
    };

    $this->allFour = ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'];

    $this->assertListed = function ($feed, array $listed): void {
        foreach ($this->allFour as $subject) {
            in_array($subject, $listed, true) ? $feed->assertSee($subject) : $feed->assertDontSee($subject);
        }
    };
});

test('the type filter works alone and with the date range', function (string $feedName, array $types, ?string $from, ?string $to, array $listed) {
    ($this->seedFour)($feedName);

    $feed = ($this->feed)($feedName)
        ->set('activityTypes', $types)
        ->set('dateFrom', $from)
        ->set('dateTo', $to);

    ($this->assertListed)($feed, $listed);
    $feed->assertSee('Clear');
})->with(function () {
    foreach (['contact', 'deal', 'company'] as $feed) {
        yield "$feed: two types" => [$feed, [ActivityType::Call->value, ActivityType::Meeting->value], null, null, ['Intro call', 'Kickoff meeting']];
        yield "$feed: types and from" => [$feed, [ActivityType::Call->value, ActivityType::Note->value], '2026-03-03', null, ['Budget note']];
        yield "$feed: range only" => [$feed, [], '2026-03-02', '2026-03-02', ['Intro call', 'Kickoff meeting']];
        yield "$feed: one type" => [$feed, [ActivityType::Note->value], null, null, ['Budget note']];
    }
});

test('an empty filtered result says so and offers Clear', function (string $feedName) {
    ($this->seedFour)($feedName);

    ($this->feed)($feedName)
        ->set('activityTypes', [ActivityType::Email->value])
        ->set('dateFrom', '2026-03-01')
        ->assertSee('No activities match these filters')
        ->assertSee('Try adjusting or clearing the filters.')
        ->assertSee('Clear')
        ->assertDontSee('No activities logged yet');
})->with(['contact', 'deal', 'company']);

test('an empty feed without filters keeps its own text', function (string $feedName) {
    ($this->feed)($feedName)
        ->assertSee('No activities logged yet')
        ->assertDontSee('No activities match these filters')
        ->assertDontSee('Clear');
})->with(['contact', 'deal', 'company']);

test('Clear resets the types and the dates', function (string $feedName) {
    ($this->seedFour)($feedName);

    $feed = ($this->feed)($feedName)
        ->set('activityTypes', [ActivityType::Call->value])
        ->set('dateFrom', '2026-03-02');

    ($this->assertListed)($feed, ['Intro call']);

    $feed->call('clearFilters')
        ->assertSet('activityTypes', [])
        ->assertSet('dateFrom', null)
        ->assertSet('dateTo', null)
        ->assertDontSee('Clear');

    ($this->assertListed)($feed, $this->allFour);
})->with(['contact', 'deal', 'company']);

test('a reversed range shows the hint and Swap them fixes it', function (string $feedName) {
    ($this->seedFour)($feedName);

    $feed = ($this->feed)($feedName)
        ->set('dateFrom', '2026-03-03')
        ->set('dateTo', '2026-03-02')
        ->assertSee('From is after To — no day can match.')
        ->assertSee('Swap them')
        ->assertSee('No activities match these filters');

    $feed->call('swapDateRange')
        ->assertSet('dateFrom', '2026-03-02')
        ->assertSet('dateTo', '2026-03-03')
        ->assertDontSee('From is after To');

    ($this->assertListed)($feed, ['Intro call', 'Kickoff meeting', 'Budget note']);
})->with(['contact', 'deal', 'company']);

test('Swap them does nothing when the range is not reversed', function () {
    ($this->feed)('deal')
        ->set('dateFrom', '2026-03-02')
        ->set('dateTo', '2026-03-03')
        ->call('swapDateRange')
        ->assertSet('dateFrom', '2026-03-02')
        ->assertSet('dateTo', '2026-03-03');
});

test('any filter change goes back to page 1', function (string $feedName, string $property, mixed $value) {
    foreach (range(1, 16) as $number) {
        ($this->logOn)($feedName, ActivityType::Call, "Call $number", '2026-03-02 10:00:00');
    }

    $pageName = ($this->pageName)($feedName);
    $feed = ($this->feed)($feedName)->call('gotoPage', 2, $pageName);

    expect($feed->instance()->getPage($pageName))->toBe(2);

    $feed->set($property, $value);

    expect($feed->instance()->getPage($pageName))->toBe(1);
})->with([
    'contact: types' => ['contact', 'activityTypes', [ActivityType::Call->value]],
    'contact: from' => ['contact', 'dateFrom', '2026-01-01'],
    'contact: to' => ['contact', 'dateTo', '2026-12-31'],
    'deal: to' => ['deal', 'dateTo', '2026-12-31'],
    'deal: types' => ['deal', 'activityTypes', [ActivityType::Call->value]],
    'company: types' => ['company', 'activityTypes', [ActivityType::Call->value]],
    'company: from' => ['company', 'dateFrom', '2026-01-01'],
]);

test('Clear and Swap them go back to page 1', function (string $feedName, string $method) {
    foreach (range(1, 16) as $number) {
        ($this->logOn)($feedName, ActivityType::Call, "Call $number", '2026-03-02 10:00:00');
    }

    $pageName = ($this->pageName)($feedName);
    $feed = ($this->feed)($feedName)
        ->set('dateFrom', '2026-03-05')
        ->set('dateTo', '2026-03-01')
        ->call('gotoPage', 2, $pageName);

    $feed->call($method);

    expect($feed->instance()->getPage($pageName))->toBe(1);
})->with([
    'contact: clear' => ['contact', 'clearFilters'],
    'deal: swap' => ['deal', 'swapDateRange'],
    'company: clear' => ['company', 'clearFilters'],
    'company: swap' => ['company', 'swapDateRange'],
]);

test('the company feed keeps the type filter outside its OR group', function () {
    $stranger = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Hooli']);
    ($this->logOn)('company', ActivityType::Note, 'Own note', '2026-03-02 10:00:00');
    ($this->logOn)('company', ActivityType::Call, 'Own call', '2026-03-02 11:00:00');
    Activity::factory()->type(ActivityType::Call)->onlyOnCompany($stranger)->create(['subject' => 'Hooli call']);

    ($this->feed)('company')
        ->set('activityTypes', [ActivityType::Call->value])
        ->assertSee('Own call')
        ->assertDontSee('Own note')
        ->assertDontSee('Hooli call');
});

test('the pills list every type in enum order and mark the selection', function () {
    $html = ($this->feed)('deal')->set('activityTypes', [ActivityType::InPerson->value])->html();

    preg_match_all('/value="([a-z_]+)"[^>]*>\s*([^<\s][^<]*?)\s*<\/label>/s', $html, $matches);

    expect($matches[1])->toBe(array_map(fn (ActivityType $type): string => $type->value, ActivityType::cases()))
        ->and($matches[2])->toBe(['Call', 'Email', 'Meeting', 'WhatsApp', 'Message', 'Note', 'In-Person', 'Other'])
        ->and($html)->toMatch('/value="in_person"\s+checked/')
        ->and($html)->not->toMatch('/value="call"\s+checked/')
        ->and($html)->toContain('<legend class="sr-only">Activity type</legend>');
});

test('forged type values are ignored and never answer 500', function (string $feedName, mixed $value, array $listed, bool $showsClear) {
    config(['app.debug' => true]);
    ($this->seedFour)($feedName);

    $feed = ($this->feed)($feedName)->set('activityTypes', $value)->assertSuccessful();

    ($this->assertListed)($feed, $listed);
    $showsClear ? $feed->assertSee('Clear') : $feed->assertDontSee('Clear');
})->with([
    'a word' => ['deal', 'nope', ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'], false],
    'a valid scalar' => ['deal', 'call', ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'], false],
    'a nested array' => ['deal', [['call']], ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'], false],
    'a label' => ['deal', ['Call'], ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'], false],
    'a padded value' => ['deal', [' call'], ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'], false],
    'mixed junk' => ['deal', ['call', 'nope', 5, null], ['Intro call'], true],
    'an object' => ['deal', ['x' => 'call'], ['Intro call'], true],
    'a duplicate' => ['deal', ['call', 'call'], ['Intro call'], true],
    'a word on the contact feed' => ['contact', 'nope', ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'], false],
    'a nested array on the contact feed' => ['contact', [['call']], ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'], false],
    'a word on the company feed' => ['company', 'nope', ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'], false],
    'a nested array on the company feed' => ['company', [['call']], ['Intro call', 'Kickoff meeting', 'Budget note', 'Follow-up email'], false],
]);

test('a forged sub-key below the types answers 419', function (string $feedName) {
    config(['app.debug' => true]);

    ($this->feed)($feedName)
        ->set('activityTypes', 'nope')
        ->set('activityTypes.0', 'call')
        ->assertStatus(419);

    ($this->feed)($feedName)
        ->set('activityTypes', [ActivityType::Call->value])
        ->set('activityTypes.0.x', 'call')
        ->assertStatus(419);
})->with(['contact', 'deal', 'company']);

test('a non-filter property update does not reset the page', function () {
    foreach (range(1, 16) as $number) {
        ($this->logOn)('deal', ActivityType::Call, "Call $number", '2026-03-02 10:00:00');
    }

    $feed = ($this->feed)('deal')->call('gotoPage', 2);
    $feed->instance()->updatedFiltersActivityFeed('somethingElse');

    expect($feed->instance()->getPage())->toBe(2);
});

test('another organization activities never show with the type filter', function () {
    $globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $globexOrg = $globex->personalOrganization();
    $globexDeal = Deal::factory()->create(['organization_id' => $globexOrg->id, 'contact_id' => null, 'title' => 'Globex deal']);
    Activity::factory()->type(ActivityType::Call)->onlyOnDeal($globexDeal)->create(['subject' => 'Globex secret call']);

    ($this->logOn)('deal', ActivityType::Call, 'Intro call', '2026-03-02 10:00:00');

    $feed = ($this->feed)('deal')->set('activityTypes', [ActivityType::Call->value])->assertSee('Intro call')->assertDontSee('Globex secret call');

    Livewire::test(DealActivityFeed::class, ['record' => $globexDeal])
        ->set('activityTypes', [ActivityType::Call->value])
        ->assertDontSee('Globex secret call');

    expect($feed->html())->not->toContain('Globex secret call');
});
