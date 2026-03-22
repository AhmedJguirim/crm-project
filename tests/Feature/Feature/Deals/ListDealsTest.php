<?php

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

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

test('deals list page loads successfully', function () {
    Livewire::test(ListDeals::class)
        ->assertSuccessful();
});

test('table view displays expected columns', function () {
    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ListDeals::class)
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('contact.name')
        ->assertTableColumnExists('value')
        ->assertTableColumnExists('stage')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('expected_close_date')
        ->assertTableColumnExists('created_at');
});

test('table can be filtered by stage status contact and closing this week', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $matchingDeal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Negotiating,
        'status' => DealStatus::Open,
        'expected_close_date' => Carbon::now()->addDay(),
    ]);

    $otherDeal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Lost,
        'status' => DealStatus::Lost,
        'expected_close_date' => Carbon::now()->addMonth(),
    ]);

    Livewire::test(ListDeals::class)
        ->filterTable('stage', [DealStage::Negotiating->value])
        ->assertCountTableRecords(1);

    Livewire::test(ListDeals::class)
        ->filterTable('status', DealStatus::Open->value)
        ->assertCountTableRecords(1);

    Livewire::test(ListDeals::class)
        ->filterTable('contact_id', $contact->id)
        ->assertCountTableRecords(1);

    Livewire::test(ListDeals::class)
        ->filterTable('closing_this_week')
        ->assertCountTableRecords(1);
});

test('pipeline board page loads successfully', function () {
    Livewire::test(DealPipeline::class)
        ->assertSuccessful();
});

test('pipeline board displays stage columns', function () {
    Livewire::test(DealPipeline::class)
        ->assertSee('Lead')
        ->assertSee('Discovery')
        ->assertSee('Proposal Sent')
        ->assertSee('Negotiating')
        ->assertSee('Won')
        ->assertSee('Lost');
});

test('pipeline board renders deal card titles', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Acme Consulting Retainer',
        'stage' => DealStage::Discovery,
        'status' => DealStatus::Open,
        'position' => '1000.0000000000',
    ]);

    Livewire::test(DealPipeline::class)
        ->assertSee('Acme Consulting Retainer');
});

test('pipeline board only shows deals from current organization', function () {
    $ourDeal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Our Org Deal',
        'stage' => DealStage::Lead,
        'status' => DealStatus::Open,
        'position' => '1000.0000000000',
    ]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $otherDeal = Deal::factory()->create([
        'organization_id' => $otherOrg->id,
        'created_by' => $otherUser->id,
        'title' => 'Other Org Secret Deal',
        'stage' => DealStage::Lead,
        'status' => DealStatus::Open,
        'position' => '1000.0000000000',
    ]);

    Livewire::test(DealPipeline::class)
        ->assertSee('Our Org Deal')
        ->assertDontSee('Other Org Secret Deal');
});

test('moving deal to won stage sets status to won and records won_at', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Negotiating,
        'status' => DealStatus::Open,
        'position' => '1000.0000000000',
        'won_at' => null,
        'lost_at' => null,
    ]);

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $deal->id, DealStage::Won->value);

    $deal->refresh();

    expect($deal->stage)->toBe(DealStage::Won)
        ->and($deal->status)->toBe(DealStatus::Won)
        ->and($deal->won_at)->not->toBeNull()
        ->and($deal->lost_at)->toBeNull();
});

test('moving deal to lost stage sets status to lost and records lost_at', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::ProposalSent,
        'status' => DealStatus::Open,
        'position' => '1000.0000000000',
        'won_at' => null,
        'lost_at' => null,
    ]);

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $deal->id, DealStage::Lost->value);

    $deal->refresh();

    expect($deal->stage)->toBe(DealStage::Lost)
        ->and($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->lost_at)->not->toBeNull()
        ->and($deal->won_at)->toBeNull();
});

test('moving deal from won back to open stage resets status and timestamps', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Won,
        'status' => DealStatus::Won,
        'position' => '1000.0000000000',
        'won_at' => now()->subDay(),
        'lost_at' => null,
    ]);

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $deal->id, DealStage::Negotiating->value);

    $deal->refresh();

    expect($deal->stage)->toBe(DealStage::Negotiating)
        ->and($deal->status)->toBe(DealStatus::Open)
        ->and($deal->won_at)->toBeNull()
        ->and($deal->lost_at)->toBeNull();
});

test('moving deal between open stages keeps status open', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Lead,
        'status' => DealStatus::Open,
        'position' => '1000.0000000000',
        'won_at' => null,
        'lost_at' => null,
    ]);

    Livewire::test(DealPipeline::class)
        ->call('moveCard', (string) $deal->id, DealStage::Discovery->value);

    $deal->refresh();

    expect($deal->stage)->toBe(DealStage::Discovery)
        ->and($deal->status)->toBe(DealStatus::Open)
        ->and($deal->won_at)->toBeNull()
        ->and($deal->lost_at)->toBeNull();
});
