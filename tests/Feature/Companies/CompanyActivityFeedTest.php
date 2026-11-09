<?php

use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Widgets\CompanyActivityFeed;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-11-09 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Initech']);
    $this->jane = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Jane Doe']);
    $this->company->contacts()->attach($this->jane);
    $this->deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'company_id' => $this->company->id,
        'created_by' => $this->user->id,
        'title' => 'Initech retainer',
    ]);
    $this->bob = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob Stone']);
    $this->hooli = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Hooli']);

    $this->logged = fn (array $attributes): Activity => Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'deal_id' => null,
        'company_id' => null,
        'user_id' => $this->user->id,
        ...$attributes,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the feed is on the company page', function () {
    Livewire::test(EditCompany::class, ['record' => $this->company->id])->assertSeeLivewire(CompanyActivityFeed::class);
});

test('own, deals and contacts activities are listed newest first and nothing else', function () {
    ($this->logged)(['subject' => 'Company note', 'company_id' => $this->company->id, 'occurred_at' => '2026-11-08 09:00:00']);
    ($this->logged)(['subject' => 'Retainer call', 'deal_id' => $this->deal->id, 'occurred_at' => '2026-11-07 09:00:00']);
    ($this->logged)(['subject' => 'Jane lunch', 'contact_id' => $this->jane->id, 'occurred_at' => '2026-11-06 09:00:00']);
    ($this->logged)(['subject' => 'Bob call', 'contact_id' => $this->bob->id, 'occurred_at' => '2026-11-05 09:00:00']);
    ($this->logged)(['subject' => 'Hooli note', 'company_id' => $this->hooli->id, 'occurred_at' => '2026-11-04 09:00:00']);
    $hooliDeal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => null,
        'company_id' => $this->hooli->id,
        'created_by' => $this->user->id,
        'title' => 'Hooli pilot',
    ]);
    ($this->logged)(['subject' => 'Hooli pilot call', 'deal_id' => $hooliDeal->id, 'occurred_at' => '2026-11-03 09:00:00']);
    $carl = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Carl Hooli']);
    $this->hooli->contacts()->attach($carl);
    ($this->logged)(['subject' => 'Carl call', 'contact_id' => $carl->id, 'occurred_at' => '2026-11-02 09:00:00']);

    $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $foreign = Company::factory()->create(['organization_id' => $otherOrg->id]);
    Activity::factory()->onlyOnCompany($foreign)->create(['subject' => 'Globex secret', 'occurred_at' => '2026-11-08 10:00:00']);

    Livewire::test(CompanyActivityFeed::class, ['record' => $this->company])
        ->assertSeeInOrder(['Company note', 'Retainer call', 'Jane lunch'])
        ->assertDontSee('Bob call')
        ->assertDontSee('Hooli note')
        ->assertDontSee('Hooli pilot call')
        ->assertDontSee('Carl call')
        ->assertDontSee('Globex secret');
});

test('an activity reached through several links is listed once', function () {
    ($this->logged)(['subject' => 'Double link', 'contact_id' => $this->jane->id, 'company_id' => $this->company->id, 'deal_id' => $this->deal->id]);

    $feed = Livewire::test(CompanyActivityFeed::class, ['record' => $this->company]);

    expect($feed->viewData('activities')->total())->toBe(1);
});

test('a detached or soft-deleted contact leaves the feed', function () {
    $max = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Max Ray']);
    $this->company->contacts()->attach($max);
    ($this->logged)(['subject' => 'Jane lunch', 'contact_id' => $this->jane->id]);
    ($this->logged)(['subject' => 'Max call', 'contact_id' => $max->id]);

    Livewire::test(CompanyActivityFeed::class, ['record' => $this->company])
        ->assertSee('Jane lunch')
        ->assertSee('Max call');

    $this->company->contacts()->detach($this->jane);
    $max->delete();

    Livewire::test(CompanyActivityFeed::class, ['record' => $this->company])
        ->assertDontSee('Jane lunch')
        ->assertDontSee('Max call');
});

test('a soft-deleted company still shows its feed', function () {
    ($this->logged)(['subject' => 'Company note', 'company_id' => $this->company->id]);
    ($this->logged)(['subject' => 'Retainer call', 'deal_id' => $this->deal->id]);
    ($this->logged)(['subject' => 'Jane lunch', 'contact_id' => $this->jane->id]);
    $this->company->delete();

    Livewire::test(CompanyActivityFeed::class, ['record' => Company::withTrashed()->find($this->company->id)])
        ->assertSee('Company note')
        ->assertSee('Retainer call')
        ->assertSee('Jane lunch');
});

test('each row says what it hangs on', function () {
    ($this->logged)(['subject' => 'Jane deal call', 'contact_id' => $this->jane->id, 'deal_id' => $this->deal->id]);

    $html = Livewire::test(CompanyActivityFeed::class, ['record' => $this->company])->html();

    expect($html)->toContain('Contact:')
        ->and($html)->toContain(ContactResource::getUrl('view', ['record' => $this->jane]))
        ->and($html)->toContain('Jane Doe')
        ->and($html)->toContain('Deal:')
        ->and($html)->toContain(DealResource::getUrl('view', ['record' => $this->deal]))
        ->and($html)->toContain('Initech retainer');
});

test('a company-only activity has no context line', function () {
    ($this->logged)(['subject' => 'Company note', 'company_id' => $this->company->id]);

    $html = Livewire::test(CompanyActivityFeed::class, ['record' => $this->company])->html();

    expect($html)->toContain('Company note')
        ->and($html)->not->toContain('Contact:')
        ->and($html)->not->toContain('Deal:');
});

test('an empty feed shows the empty state', function () {
    Livewire::test(CompanyActivityFeed::class, ['record' => $this->hooli])->assertSee('No activities logged yet');
});

test('the feed is paginated and refreshes to the first page after logging', function () {
    foreach (range(1, 16) as $n) {
        ($this->logged)([
            'subject' => sprintf('Note %02d', $n),
            'company_id' => $this->company->id,
            'occurred_at' => Carbon::parse('2026-11-01 00:00:00')->addHours($n),
        ]);
    }

    Livewire::test(CompanyActivityFeed::class, ['record' => $this->company])
        ->assertSee('Note 16')
        ->assertDontSee('Note 01')
        ->call('gotoPage', 2, 'activitiesPage')
        ->assertSee('Note 01')
        ->dispatch('activityLogged')
        ->assertSee('Note 16');
});

test('a company without a record shows nothing and does not fail', function () {
    ($this->logged)(['subject' => 'Company note', 'company_id' => $this->company->id]);

    Livewire::test(CompanyActivityFeed::class)->assertDontSee('Company note')->assertSee('No activities logged yet');
});
