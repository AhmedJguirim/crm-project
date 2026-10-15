<?php

use App\Enums\Currency;
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
    Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'value' => 1000,
    ]);

    Deal::factory()->inStage('Negotiating')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'value' => 2000,
    ]);

    Deal::factory()->inStage('Won')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Won,
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

    Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'value' => 500,
    ]);

    Deal::factory()->inStage('Discovery')->create([
        'organization_id' => $otherOrg->id,
        'created_by' => $otherUser->id,
        'status' => DealStatus::Open,
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

function dealsOverviewOpenDeal(object $test, ?float $value, string $stage = 'Lead'): Deal
{
    return Deal::factory()->inStage($stage)->create([
        'organization_id' => $test->org->id,
        'created_by' => $test->user->id,
        'status' => DealStatus::Open,
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
    Deal::factory()->inStage('Won')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Won,
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
    Deal::factory()->inStage('Won')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Won,
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
    Deal::factory()->inStage('Lead')->create([
        'organization_id' => $otherOrg->id,
        'created_by' => $otherUser->id,
        'status' => DealStatus::Open,
        'value' => 9000,
    ]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSee('$500.00')
        ->assertDontSee('9,000')
        ->assertDontSee('€');
});

function dealsOverviewStatValues(DealsOverviewWidget $widget): array
{
    $stats = (fn (): array => $this->getStats())->call($widget);

    $values = [];

    foreach ($stats as $stat) {
        $values[$stat->getLabel()] = (string) $stat->getValue();
    }

    return $values;
}

test('the cards hold the counts of the open and of the won deals, not each other', function () {
    foreach ([100, 200, 300] as $value) {
        dealsOverviewOpenDeal($this, $value);
    }

    foreach ([5000, 7000] as $value) {
        Deal::factory()->inStage('Won')->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->user->id,
            'status' => DealStatus::Won,
            'value' => $value,
            'won_at' => now(),
        ]);
    }

    $values = dealsOverviewStatValues(Livewire::test(DealsOverviewWidget::class)->instance());

    expect($values['Open Deals'])->toBe('3')
        ->and($values['Won This Month'])->toBe('2')
        ->and($values['Pipeline Value'])->toBe('$600.00')
        ->and($values['Revenue Won This Month'])->toBe('$12,000.00');
});
