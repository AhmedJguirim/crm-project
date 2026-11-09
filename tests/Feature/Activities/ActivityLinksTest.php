<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ActivityType;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Jobs\ResyncContactSegments;
use App\Jobs\SyncSegmentMembership;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\DeleteBulkAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->globex = Organization::factory()->create(['name' => 'Globex']);

    $this->ada = Contact::factory()->for($this->org)->create(['name' => 'Ada Lovelace']);
    $this->initech = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Initech']);
    $this->redesign = Deal::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id, 'title' => 'Website redesign']);

    $this->grace = Contact::factory()->for($this->globex)->create(['name' => 'Grace Hopper']);
    $this->umbrella = Company::factory()->create(['organization_id' => $this->globex->id, 'name' => 'Umbrella']);
    $this->globexDeal = Deal::factory()->create(['organization_id' => $this->globex->id, 'created_by' => $this->user->id, 'title' => 'Globex deal']);

    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->newActivity = fn (array $attributes): Activity => Activity::create($attributes + [
        'user_id' => $this->user->id,
        'type' => ActivityType::Call,
        'occurred_at' => now(),
    ]);
});

test('an activity saves with one link only', function (string $link) {
    $attributes = match ($link) {
        'contact' => ['contact_id' => $this->ada->id],
        'deal' => ['deal_id' => $this->redesign->id],
        'company' => ['company_id' => $this->initech->id],
    };

    $activity = ($this->newActivity)(['organization_id' => $this->org->id] + $attributes)->fresh();

    expect($activity->contact_id)->toBe($attributes['contact_id'] ?? null)
        ->and($activity->deal_id)->toBe($attributes['deal_id'] ?? null)
        ->and($activity->company_id)->toBe($attributes['company_id'] ?? null);
})->with(['contact', 'deal', 'company']);

test('the factory states put an activity on a deal or a company only', function () {
    $onDeal = Activity::factory()->onlyOnDeal($this->redesign)->create(['user_id' => $this->user->id]);
    $onCompany = Activity::factory()->onlyOnCompany($this->initech)->create(['user_id' => $this->user->id]);

    expect($onDeal->contact_id)->toBeNull()
        ->and($onDeal->deal_id)->toBe($this->redesign->id)
        ->and($onDeal->company_id)->toBeNull()
        ->and($onCompany->contact_id)->toBeNull()
        ->and($onCompany->company_id)->toBe($this->initech->id)
        ->and($onCompany->deal_id)->toBeNull()
        ->and($onCompany->company->is($this->initech))->toBeTrue()
        ->and($this->initech->activities()->pluck('id')->all())->toBe([$onCompany->id]);
});

test('the database refuses an activity linked to nothing', function () {
    DB::transaction(fn () => DB::table('contact_activities')->insert([
        'organization_id' => $this->org->id,
        'user_id' => $this->user->id,
        'contact_id' => null,
        'deal_id' => null,
        'company_id' => null,
        'type' => ActivityType::Call->value,
        'occurred_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]));
})->throws(QueryException::class, 'contact_activities_linked_record_check');

test('the database refuses to clear the last link of an activity', function () {
    $activity = ($this->newActivity)(['organization_id' => $this->org->id, 'deal_id' => $this->redesign->id]);

    DB::transaction(fn () => $activity->update(['deal_id' => null]));
})->throws(QueryException::class, 'contact_activities_linked_record_check');

test('the organization comes from the contact, the deal or the company, with no tenant context', function () {
    Filament::setTenant(null);
    expect(app(TenantContext::class)->id())->toBeNull();

    $fromContact = Activity::create(['contact_id' => $this->grace->id, 'user_id' => $this->user->id, 'type' => ActivityType::Call, 'occurred_at' => now()]);
    $fromDeal = Activity::create(['deal_id' => $this->globexDeal->id, 'user_id' => $this->user->id, 'type' => ActivityType::Call, 'occurred_at' => now()]);
    $fromCompany = Activity::create(['company_id' => $this->umbrella->id, 'user_id' => $this->user->id, 'type' => ActivityType::Call, 'occurred_at' => now()]);

    expect($fromContact->organization_id)->toBe($this->globex->id)
        ->and($fromDeal->organization_id)->toBe($this->globex->id)
        ->and($fromCompany->organization_id)->toBe($this->globex->id);
});

test('an activity created with no organization and no link is refused by the database', function () {
    Filament::setTenant(null);

    DB::transaction(fn () => Activity::create(['user_id' => $this->user->id, 'type' => ActivityType::Call, 'occurred_at' => now()]));
})->throws(QueryException::class);

test('a link to another organization\'s record is refused', function (string $field) {
    $id = match ($field) {
        'contact_id' => $this->grace->id,
        'deal_id' => $this->globexDeal->id,
        'company_id' => $this->umbrella->id,
    };

    expect(fn () => ($this->newActivity)(['organization_id' => $this->org->id, $field => $id, 'contact_id' => $field === 'contact_id' ? $id : $this->ada->id]))
        ->toThrow(ModelNotFoundException::class)
        ->and(Activity::withoutGlobalScopes()->count())->toBe(0);
})->with(['contact_id', 'deal_id', 'company_id']);

test('a link to another organization\'s record is refused when the organization comes from the tenant', function (string $field) {
    $id = match ($field) {
        'contact_id' => $this->grace->id,
        'deal_id' => $this->globexDeal->id,
        'company_id' => $this->umbrella->id,
    };

    expect(fn () => ($this->newActivity)([$field => $id]))->toThrow(ModelNotFoundException::class)
        ->and(Activity::withoutGlobalScopes()->count())->toBe(0);
})->with(['contact_id', 'deal_id', 'company_id']);

test('a nonexistent link id is refused', function () {
    ($this->newActivity)(['organization_id' => $this->org->id, 'contact_id' => $this->ada->id, 'deal_id' => 999999999]);
})->throws(ModelNotFoundException::class);

test('changing a link on an existing activity to another organization\'s deal is refused', function () {
    $activity = ($this->newActivity)(['organization_id' => $this->org->id, 'contact_id' => $this->ada->id]);

    expect(fn () => $activity->update(['deal_id' => $this->globexDeal->id]))->toThrow(ModelNotFoundException::class)
        ->and($activity->fresh()->deal_id)->toBeNull();
});

test('an existing activity can be edited without its links being checked again', function () {
    $activity = ($this->newActivity)(['organization_id' => $this->org->id, 'contact_id' => $this->ada->id, 'deal_id' => $this->redesign->id]);
    $this->redesign->delete();
    $activity = $activity->fresh();

    $activity->update(['subject' => 'Follow up']);

    expect($activity->fresh()->subject)->toBe('Follow up');
});

test('a soft-deleted contact or company is still accepted', function () {
    $this->ada->delete();
    $this->initech->delete();

    $onContact = ($this->newActivity)(['organization_id' => $this->org->id, 'contact_id' => $this->ada->id]);
    $onCompany = ($this->newActivity)(['organization_id' => $this->org->id, 'company_id' => $this->initech->id]);

    expect($onContact->exists)->toBeTrue()
        ->and($onCompany->exists)->toBeTrue();
});

test('deleting a deal removes its deal-only activities and keeps the others', function () {
    $dealOnly = Activity::factory()->onlyOnDeal($this->redesign)->create(['user_id' => $this->user->id]);
    $withContact = ($this->newActivity)(['organization_id' => $this->org->id, 'deal_id' => $this->redesign->id, 'contact_id' => $this->ada->id]);
    $withCompany = ($this->newActivity)(['organization_id' => $this->org->id, 'deal_id' => $this->redesign->id, 'company_id' => $this->initech->id]);
    $otherDealOnly = Activity::factory()->onlyOnDeal(Deal::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id]))->create(['user_id' => $this->user->id]);

    $this->redesign->delete();

    expect(Activity::find($dealOnly->id))->toBeNull()
        ->and($withContact->fresh()->deal_id)->toBeNull()
        ->and($withContact->fresh()->contact_id)->toBe($this->ada->id)
        ->and($withCompany->fresh()->deal_id)->toBeNull()
        ->and($withCompany->fresh()->company_id)->toBe($this->initech->id)
        ->and(Activity::find($otherDealOnly->id))->not->toBeNull();
});

test('bulk-deleting deals from the list does the same', function () {
    $dealOnly = Activity::factory()->onlyOnDeal($this->redesign)->create(['user_id' => $this->user->id]);
    $withContact = ($this->newActivity)(['organization_id' => $this->org->id, 'deal_id' => $this->redesign->id, 'contact_id' => $this->ada->id]);

    Livewire::test(ListDeals::class)
        ->callTableBulkAction(DeleteBulkAction::class, [$this->redesign])
        ->assertNotified();

    expect(Deal::find($this->redesign->id))->toBeNull()
        ->and(Activity::find($dealOnly->id))->toBeNull()
        ->and($withContact->fresh()->deal_id)->toBeNull();
});

test('deleting an organization with deal-only, company-only and fully linked activities removes them all', function () {
    Activity::factory()->onlyOnDeal($this->redesign)->create(['user_id' => $this->user->id]);
    Activity::factory()->onlyOnCompany($this->initech)->create(['user_id' => $this->user->id]);
    ($this->newActivity)(['organization_id' => $this->org->id, 'contact_id' => $this->ada->id, 'deal_id' => $this->redesign->id, 'company_id' => $this->initech->id]);
    $globexActivity = Activity::factory()->onlyOnDeal($this->globexDeal)->create(['user_id' => $this->user->id]);

    expect(DB::table('contact_activities')->where('organization_id', $this->org->id)->count())->toBe(3);

    DB::transaction(fn () => DB::table('organizations')->where('id', $this->org->id)->delete());

    expect(DB::table('contact_activities')->where('organization_id', $this->org->id)->count())->toBe(0)
        ->and(DB::table('deals')->where('organization_id', $this->org->id)->count())->toBe(0)
        ->and(DB::table('contact_activities')->where('id', $globexActivity->id)->exists())->toBeTrue();
});

test('a contact-less activity does not affect segments', function () {
    $segment = Segment::factory()->for($this->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'Called', [
            SegmentConditionData::make(SegmentConditionType::Activity, null, SegmentOperator::HasHadActivity, ['activity_types' => [ActivityType::Call->value], 'days' => 30]),
        ]),
    ])->create();
    Queue::fake();

    Activity::factory()->onlyOnDeal($this->redesign)->type(ActivityType::Call)->create(['user_id' => $this->user->id, 'occurred_at' => now()]);
    Activity::factory()->onlyOnCompany($this->initech)->type(ActivityType::Call)->create(['user_id' => $this->user->id, 'occurred_at' => now()]);

    Queue::assertNotPushed(ResyncContactSegments::class);

    SyncSegmentMembership::dispatchSync($segment->id);

    expect($segment->contacts()->withoutGlobalScope('organization')->count())->toBe(0);
});

test('a bare factory activity is coherent', function () {
    $activity = Activity::factory()->create();

    expect(Contact::withoutGlobalScopes()->findOrFail($activity->contact_id)->organization_id)->toBe($activity->organization_id);
});
