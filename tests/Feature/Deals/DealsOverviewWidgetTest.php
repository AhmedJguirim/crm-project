<?php

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Widgets\DealsOverviewWidget;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 11:30:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('deals overview widget renders metrics and stage breakdown for current tenant', function () {
    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'stage' => DealStage::Lead,
        'value' => 1000,
        'currency' => 'USD',
    ]);

    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'stage' => DealStage::Negotiating,
        'value' => 2000,
        'currency' => 'USD',
    ]);

    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Won,
        'stage' => DealStage::Won,
        'value' => 700,
        'currency' => 'USD',
        'won_at' => now()->subDays(2),
    ]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Open Deals')
        ->assertSee('2')
        ->assertSee('Pipeline Value')
        ->assertSee('$3,000.00')
        ->assertSee('Won This Month')
        ->assertSee('Revenue Won This Month')
        ->assertSee('$700.00')
        ->assertSee('Open Deals by Stage')
        ->assertSee('Lead: 1')
        ->assertSee('Negotiating: 1')
        ->assertDontSee('Won: 1');
});

test('deals overview widget ignores deals from other organizations', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'stage' => DealStage::Lead,
        'value' => 500,
        'currency' => 'USD',
    ]);

    Deal::factory()->create([
        'organization_id' => $otherOrg->id,
        'created_by' => $otherUser->id,
        'status' => DealStatus::Open,
        'stage' => DealStage::Discovery,
        'value' => 9000,
        'currency' => 'USD',
    ]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Open Deals')
        ->assertSee('$500.00')
        ->assertSee('Lead: 1')
        ->assertDontSee('Discovery: 1')
        ->assertDontSee('$9,000.00');
});
