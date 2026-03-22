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
