<?php

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('deal casts stage and status to enums', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Discovery,
        'status' => DealStatus::Open,
    ]);

    expect($deal->fresh()->stage)->toBe(DealStage::Discovery)
        ->and($deal->fresh()->status)->toBe(DealStatus::Open);
});

test('deal belongs to organization contact and creator', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
    ]);

    expect($deal->organization->is($this->org))->toBeTrue()
        ->and($deal->contact?->is($contact))->toBeTrue()
        ->and($deal->creator->is($this->user))->toBeTrue();
});

test('global scope limits deals to current tenant organization', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $ownDeal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    $otherDeal = Deal::factory()->create([
        'organization_id' => $otherOrg->id,
        'created_by' => $otherUser->id,
    ]);

    expect(Deal::query()->pluck('id')->all())
        ->toContain($ownDeal->id)
        ->not->toContain($otherDeal->id);
});

test('status scopes return expected records', function () {
    $openDeal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
    ]);

    $wonDeal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Won,
    ]);

    $lostDeal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Lost,
    ]);

    expect(Deal::open()->pluck('id')->all())->toContain($openDeal->id)->not->toContain($wonDeal->id)
        ->and(Deal::won()->pluck('id')->all())->toContain($wonDeal->id)->not->toContain($lostDeal->id)
        ->and(Deal::lost()->pluck('id')->all())->toContain($lostDeal->id)->not->toContain($openDeal->id);
});

test('stage and closing this week scopes work correctly', function () {
    $negotiatingThisWeek = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Negotiating,
        'expected_close_date' => Carbon::now()->addDay(),
    ]);

    $leadNextMonth = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Lead,
        'expected_close_date' => Carbon::now()->addMonth(),
    ]);

    expect(Deal::byStage(DealStage::Negotiating)->pluck('id')->all())
        ->toContain($negotiatingThisWeek->id)
        ->not->toContain($leadNextMonth->id)
        ->and(Deal::closingThisWeek()->pluck('id')->all())
        ->toContain($negotiatingThisWeek->id)
        ->not->toContain($leadNextMonth->id);
});

test('a deal is overdue only when it is open and its close date is before today', function (DealStatus $status, ?string $date, bool $expected) {
    $deal = Deal::factory()->make([
        'organization_id' => $this->org->id,
        'status' => $status,
        'expected_close_date' => $date,
    ]);

    expect($deal->isOverdue())->toBe($expected);
})->with([
    'open, yesterday' => [DealStatus::Open, '2026-02-20', true],
    'open, today' => [DealStatus::Open, '2026-02-21', false],
    'open, tomorrow' => [DealStatus::Open, '2026-02-22', false],
    'open, no date' => [DealStatus::Open, null, false],
    'won, past' => [DealStatus::Won, '2026-02-01', false],
    'lost, past' => [DealStatus::Lost, '2026-02-01', false],
]);

test('the overdue day boundary is midnight', function () {
    $deal = Deal::factory()->make([
        'organization_id' => $this->org->id,
        'status' => DealStatus::Open,
        'expected_close_date' => '2026-02-20',
    ]);

    Carbon::setTestNow('2026-02-21 00:00:00');
    expect($deal->isOverdue())->toBeTrue();

    Carbon::setTestNow('2026-02-20 23:59:59');
    expect($deal->isOverdue())->toBeFalse();
});
