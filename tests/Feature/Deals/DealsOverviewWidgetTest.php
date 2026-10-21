<?php

use App\Enums\Currency;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ContactsOverviewWidget;
use App\Filament\Widgets\DealForecastWidget;
use App\Filament\Widgets\DealsOverviewWidget;
use App\Filament\Widgets\KpiOverviewWidget;
use App\Models\Deal;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        ->assertDontSee('Won This Month')
        ->assertDontSee('Revenue Won This Month')
        ->assertDontSee('$700.00')
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
        ->assertDontSee('$3,000.00');

    Livewire::test(KpiOverviewWidget::class)
        ->assertSeeInOrder(['Revenue Won This Month', '€700.00'])
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

    Livewire::test(KpiOverviewWidget::class)
        ->assertSeeInOrder(['Revenue Won This Month', '$700.00', 'Up from $0.00']);
});

test('an organization without deals shows zero amounts in its currency', function (Currency $currency, string $zero) {
    $this->org->update(['currency' => $currency]);

    Livewire::test(DealsOverviewWidget::class)
        ->assertSeeInOrder(['Pipeline Value', $zero]);

    Livewire::test(KpiOverviewWidget::class)
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

function dealsOverviewStatValues(DealsOverviewWidget|KpiOverviewWidget|ContactsOverviewWidget $widget): array
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
    $kpis = dealsOverviewStatValues(Livewire::test(KpiOverviewWidget::class)->instance());

    expect($values)->toBe(['Open Deals' => '3', 'Pipeline Value' => '$600.00'])
        ->and($kpis['Deals Won This Month'])->toBe('2')
        ->and($kpis['Revenue Won This Month'])->toBe('$12,000.00');
});

describe('dashboard filters', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));
        $this->org->update(['timezone' => 'Europe/Paris']);
        Filament::setTenant($this->org->refresh());

        $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Brown']);
        $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);

        $this->filteredDeal = fn (string $stage, DealStatus $status, string $value, ?User $owner, ?string $wonAt = null): Deal => Deal::factory()->inStage($stage)->create([
            'organization_id' => $this->org->id,
            'created_by' => $this->user->id,
            'owner_id' => $owner?->id,
            'status' => $status,
            'value' => $value,
            'won_at' => $wonAt,
        ]);

        ($this->filteredDeal)('Lead', DealStatus::Open, '1000', $this->user);
        ($this->filteredDeal)('Negotiating', DealStatus::Open, '2000', $this->bob);
        ($this->filteredDeal)('Lead', DealStatus::Open, '500', null);
        ($this->filteredDeal)('Won', DealStatus::Won, '700', $this->user, '2026-10-05 10:00:00');
        ($this->filteredDeal)('Won', DealStatus::Won, '900', $this->bob, '2026-09-10 10:00:00');
        ($this->filteredDeal)('Won', DealStatus::Won, '300', $this->user, '2026-09-30 22:30:00');
    });

    $statValues = fn (array $pageFilters): array => dealsOverviewStatValues(Livewire::test(DealsOverviewWidget::class, ['pageFilters' => $pageFilters])->instance());
    $kpiValues = fn (array $pageFilters): array => dealsOverviewStatValues(Livewire::test(KpiOverviewWidget::class, ['pageFilters' => $pageFilters])->instance());

    test('without filters the figures are the whole organization and this month', function () use ($statValues, $kpiValues) {
        expect($statValues([]))->toBe([
            'Open Deals' => '3',
            'Pipeline Value' => '$3,500.00',
        ])->and($kpiValues([]))->toMatchArray([
            'Deals Won This Month' => '2',
            'Revenue Won This Month' => '$1,000.00',
        ]);

        Livewire::test(KpiOverviewWidget::class)
            ->assertSee('Compared with the previous period, from Sep 1, 2026 to Sep 30, 2026.');
    });

    test('the period moves the won figures and their wording, not the open ones', function () use ($statValues, $kpiValues) {
        expect($statValues(['period' => 'last_month']))->toBe([
            'Open Deals' => '3',
            'Pipeline Value' => '$3,500.00',
        ])->and($kpiValues(['period' => 'last_month']))->toMatchArray([
            'Deals Won Last Month' => '1',
            'Revenue Won Last Month' => '$900.00',
        ]);

        Livewire::test(KpiOverviewWidget::class, ['pageFilters' => ['period' => 'last_month']])
            ->assertSee('Compared with the previous period, from Aug 1, 2026 to Aug 31, 2026.');
    });

    test('the owner narrows every figure and the stage breakdown', function () use ($statValues, $kpiValues) {
        expect($statValues(['owner' => 'me']))->toBe([
            'Open Deals' => '1',
            'Pipeline Value' => '$1,000.00',
        ])->and($kpiValues(['owner' => 'me']))->toMatchArray([
            'Deals Won This Month' => '2',
            'Revenue Won This Month' => '$1,000.00',
        ]);

        Livewire::test(DealsOverviewWidget::class, ['pageFilters' => ['owner' => 'me']])
            ->assertSee('Lead: 1')
            ->assertDontSee('Negotiating: 1');
    });

    test('the owner narrows the won figures of the period too', function () use ($kpiValues) {
        expect($kpiValues(['owner' => (string) $this->bob->id]))->toMatchArray(['Deals Won This Month' => '0', 'Revenue Won This Month' => '$0.00'])
            ->and($kpiValues(['owner' => 'me', 'period' => 'last_month']))->toMatchArray(['Deals Won Last Month' => '0', 'Revenue Won Last Month' => '$0.00']);
    });

    test('an owner and a period combine', function () use ($statValues, $kpiValues) {
        $filters = ['owner' => (string) $this->bob->id, 'period' => 'last_month'];

        expect($statValues($filters))->toBe([
            'Open Deals' => '1',
            'Pipeline Value' => '$2,000.00',
        ])->and($kpiValues($filters))->toMatchArray([
            'Deals Won Last Month' => '1',
            'Revenue Won Last Month' => '$900.00',
        ]);

        Livewire::test(DealsOverviewWidget::class, ['pageFilters' => $filters])
            ->assertSee('Negotiating: 1')
            ->assertDontSee('Lead: 1');
    });

    test('a custom range counts the won deals of its local days', function () use ($kpiValues) {
        $filters = ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-10-31'];

        expect($kpiValues($filters))->toMatchArray([
            'Deals Won In Period' => '3',
            'Revenue Won In Period' => '$1,900.00',
        ]);

        Livewire::test(KpiOverviewWidget::class, ['pageFilters' => $filters])
            ->assertSee('Compared with the previous period, from Jul 2, 2026 to Aug 31, 2026.');
    });

    test('the end of a custom range includes its whole last local day', function () use ($kpiValues) {
        expect($kpiValues(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-05']))->toMatchArray(['Deals Won In Period' => '2'])
            ->and($kpiValues(['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-04']))->toMatchArray(['Deals Won In Period' => '1'])
            ->and($kpiValues(['period' => 'custom', 'from' => '2026-09-30', 'to' => '2026-09-30']))->toMatchArray(['Deals Won In Period' => '0']);
    });

    test('an owner of another organization or a forged value shows everyone', function (callable $owner) use ($statValues) {
        $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $globex = $carol->personalOrganization();
        Deal::factory()->inStage('Lead')->create([
            'organization_id' => $globex->id,
            'created_by' => $carol->id,
            'owner_id' => $carol->id,
            'status' => DealStatus::Open,
            'value' => 9000,
        ]);

        expect($statValues(['owner' => $owner($carol)]))->toMatchArray(['Open Deals' => '3', 'Pipeline Value' => '$3,500.00']);
    })->with([
        'a user of another organization' => [fn (User $carol): string => (string) $carol->id],
        'an injection' => [fn (): string => '1 OR 1=1'],
        'an array' => [fn (): array => ['1']],
    ]);

    test('the dashboard hands its filters to the widgets', function () {
        $page = Livewire::test(Dashboard::class)->set('filters.owner', 'me')->set('filters.period', 'last_month');

        foreach ([DealsOverviewWidget::class, DealForecastWidget::class, KpiOverviewWidget::class, ContactsOverviewWidget::class] as $widget) {
            $component = $page->instance()->getWidgetsSchemaComponents([$widget])[0];

            expect($component->getData()['pageFilters'])->toMatchArray(['owner' => 'me', 'period' => 'last_month']);
        }
    });

    test('the breakdown is not reused when the figures are recomputed with other filters', function () {
        $widget = Livewire::test(DealsOverviewWidget::class)->instance();

        expect($widget->getOpenStageBreakdown())->toBe(['Lead' => 2, 'Negotiating' => 1]);

        $widget->pageFilters = ['owner' => (string) $this->bob->id];
        (fn (): array => $this->getStats())->call($widget);

        expect($widget->getOpenStageBreakdown())->toBe(['Negotiating' => 1]);
    });
});
