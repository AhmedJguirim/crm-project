<?php

use App\Enums\Currency;
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
    ]);

    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'stage' => DealStage::Negotiating,
        'value' => 2000,
    ]);

    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Won,
        'stage' => DealStage::Won,
        'value' => 700,
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
    ]);

    Deal::factory()->create([
        'organization_id' => $otherOrg->id,
        'created_by' => $otherUser->id,
        'status' => DealStatus::Open,
        'stage' => DealStage::Discovery,
        'value' => 9000,
    ]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSuccessful()
        ->assertSee('Open Deals')
        ->assertSee('$500.00')
        ->assertSee('Lead: 1')
        ->assertDontSee('Discovery: 1')
        ->assertDontSee('$9,000.00');
});

function dealsOverviewOpenDeal(object $test, ?float $value, DealStage $stage = DealStage::Lead): Deal
{
    return Deal::factory()->create([
        'organization_id' => $test->org->id,
        'created_by' => $test->user->id,
        'status' => DealStatus::Open,
        'stage' => $stage,
        'value' => $value,
    ]);
}

test('pipeline value adds every open deal of the organization and says nothing about currencies', function () {
    foreach ([85000, 42000, 65000, 30000] as $value) {
        dealsOverviewOpenDeal($this, $value);
    }

    Livewire::test(DealsOverviewWidget::class)
        ->assertSeeInOrder(['Pipeline Value', '$222,000.00', 'Total open pipeline value'])
        ->assertDontSee('Multiple currencies')
        ->assertSeeInOrder(['Open Deals', '4']);
});

test('the cards use the currency of the organization', function () {
    $this->org->update(['currency' => Currency::Eur]);

    dealsOverviewOpenDeal($this, 1000);
    dealsOverviewOpenDeal($this, 2000);
    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Won,
        'stage' => DealStage::Won,
        'value' => 700,
        'won_at' => now()->subDays(2),
    ]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSeeInOrder(['Pipeline Value', '€3,000.00'])
        ->assertSeeInOrder(['Revenue Won This Month', '€700.00'])
        ->assertDontSee('$3,000.00')
        ->assertDontSee('$700.00');
});

test('a deal without a value counts as a deal and adds nothing to the pipeline', function () {
    dealsOverviewOpenDeal($this, 1000);
    dealsOverviewOpenDeal($this, null);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSeeInOrder(['Open Deals', '2'])
        ->assertSeeInOrder(['Pipeline Value', '$1,000.00']);
});

test('revenue won this month counts only won deals, not the open ones', function () {
    dealsOverviewOpenDeal($this, 1000);
    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Won,
        'stage' => DealStage::Won,
        'value' => 700,
        'won_at' => now()->subDays(2),
    ]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSeeInOrder(['Revenue Won This Month', '$700.00', 'Won deal value this month']);
});

test('an organization without deals shows zero amounts in its currency', function (Currency $currency, string $zero) {
    $this->org->update(['currency' => $currency]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSeeInOrder(['Pipeline Value', $zero])
        ->assertSeeInOrder(['Revenue Won This Month', $zero]);
})->with([
    'usd' => [Currency::Usd, '$0.00'],
    'eur' => [Currency::Eur, '€0.00'],
]);

test('the amounts of another organization do not leak into the overview', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();
    $otherOrg->update(['currency' => Currency::Eur]);

    dealsOverviewOpenDeal($this, 500);
    Deal::factory()->create([
        'organization_id' => $otherOrg->id,
        'created_by' => $otherUser->id,
        'status' => DealStatus::Open,
        'stage' => DealStage::Lead,
        'value' => 9000,
    ]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSee('$500.00')
        ->assertDontSee('9,000')
        ->assertDontSee('€');
});
