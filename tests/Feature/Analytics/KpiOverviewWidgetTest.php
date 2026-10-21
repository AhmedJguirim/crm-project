<?php

use App\Enums\ContactStatus;
use App\Enums\Currency;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Widgets\ContactsOverviewWidget;
use App\Filament\Widgets\KpiOverviewWidget;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Livewire\invade;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['timezone' => 'Europe/Paris']);
    $this->actingAs($this->user);
    Filament::setTenant($this->org->refresh());

    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Brown']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);

    $this->kpiDeal = fn (DealStatus $status, string $value, ?User $owner, string $createdAt, ?string $closedAt = null, ?Organization $organization = null): Deal => Deal::factory()
        ->inStage(match ($status) {
            DealStatus::Open => 'Lead',
            DealStatus::Won => 'Won',
            DealStatus::Lost => 'Lost',
        })
        ->create([
            'organization_id' => ($organization ?? $this->org)->id,
            'created_by' => $this->user->id,
            'owner_id' => $owner?->id,
            'status' => $status,
            'value' => $value,
            'won_at' => $status === DealStatus::Won ? $closedAt : null,
            'lost_at' => $status === DealStatus::Lost ? $closedAt : null,
            'created_at' => $createdAt,
        ]);

    $this->kpiContact = fn (?User $owner, string $createdAt, ?Organization $organization = null): Contact => Contact::factory()->create([
        'organization_id' => ($organization ?? $this->org)->id,
        'owner_id' => $owner?->id,
        'created_at' => $createdAt,
    ]);

    $this->kpiStats = function (array $pageFilters = [], string $widget = KpiOverviewWidget::class): array {
        $stats = [];

        foreach (invade(Livewire::test($widget, ['pageFilters' => $pageFilters])->instance())->getStats() as $stat) {
            /** @var Stat $stat */
            $stats[(string) $stat->getLabel()] = [
                'value' => (string) $stat->getValue(),
                'description' => (string) $stat->getDescription(),
                'icon' => $stat->getDescriptionIcon(),
                'color' => $stat->getDescriptionColor(),
            ];
        }

        return $stats;
    };

    $this->kpiSummary = fn (array $stats): array => array_map(fn (array $stat): array => [$stat['value'], $stat['description']], $stats);
});

afterEach(function () {
    Filament::setTenant(null);
});

describe('with the mixed deals and contacts of two months', function () {
    beforeEach(function () {
        ($this->kpiDeal)(DealStatus::Won, '700', $this->user, '2026-09-15 10:00:00', '2026-10-05 10:00:00');
        ($this->kpiDeal)(DealStatus::Won, '900', $this->bob, '2026-08-20 10:00:00', '2026-09-10 10:00:00');
        ($this->kpiDeal)(DealStatus::Won, '300', $this->user, '2026-09-30 21:30:00', '2026-09-30 22:30:00');
        ($this->kpiDeal)(DealStatus::Lost, '400', $this->user, '2026-10-02 09:00:00', '2026-10-12 09:00:00');
        ($this->kpiDeal)(DealStatus::Lost, '250', $this->bob, '2026-09-05 09:00:00', '2026-09-20 09:00:00');
        ($this->kpiDeal)(DealStatus::Lost, '100', $this->bob, '2026-09-06 09:00:00', '2026-09-25 09:00:00');
        ($this->kpiDeal)(DealStatus::Open, '1000', $this->user, '2026-10-21 10:00:00');
        ($this->kpiDeal)(DealStatus::Open, '2000', $this->bob, '2026-10-03 08:00:00');
        ($this->kpiDeal)(DealStatus::Open, '500', null, '2026-08-01 10:00:00');

        $this->contactC1 = ($this->kpiContact)($this->user, '2026-10-10 10:00:00');
        ($this->kpiContact)($this->bob, '2026-09-30 22:30:00');
        ($this->kpiContact)($this->user, '2026-09-15 10:00:00');
        ($this->kpiContact)(null, '2026-09-30 21:30:00');
    });

    test('everyone this month is compared with the previous month', function () {
        $stats = ($this->kpiStats)();

        expect(($this->kpiSummary)($stats))->toBe([
            'Revenue Won This Month' => ['$1,000.00', '+11.1% from $900.00'],
            'Deals Won This Month' => ['2', '+100% from 1'],
            'Win Rate This Month' => ['67%', '2 of 3 won · +34 pts from 33%'],
            'New Deals This Month' => ['3', '-25% from 4'],
            'New Contacts This Month' => ['2', 'Same as previous period'],
        ])
            ->and($stats['Revenue Won This Month']['icon'])->toBe(Heroicon::ArrowTrendingUp)
            ->and($stats['Revenue Won This Month']['color'])->toBe('success')
            ->and($stats['Win Rate This Month']['icon'])->toBe(Heroicon::ArrowTrendingUp)
            ->and($stats['New Deals This Month']['icon'])->toBe(Heroicon::ArrowTrendingDown)
            ->and($stats['New Deals This Month']['color'])->toBe('danger')
            ->and($stats['New Contacts This Month']['icon'])->toBe(Heroicon::Minus)
            ->and($stats['New Contacts This Month']['color'])->toBe('gray');

        Livewire::test(KpiOverviewWidget::class)
            ->assertSee('Key figures')
            ->assertSee('Compared with the previous period, from Sep 1, 2026 to Sep 30, 2026.');
    });

    test('the figures link to the lists they come from', function () {
        $stats = invade(Livewire::test(KpiOverviewWidget::class)->instance())->getStats();

        expect(collect($stats)->map(fn (Stat $stat): ?string => $stat->getUrl())->all())->toBe([
            DealResource::getUrl('index').'?tableFilters[status][value]=won',
            DealResource::getUrl('index').'?tableFilters[status][value]=won',
            null,
            DealResource::getUrl('index'),
            ContactResource::getUrl('index'),
        ]);
    });

    test('the owner narrows every figure and its comparison', function () {
        expect(($this->kpiSummary)(($this->kpiStats)(['owner' => 'me'])))->toBe([
            'Revenue Won This Month' => ['$1,000.00', 'Up from $0.00'],
            'Deals Won This Month' => ['2', 'Up from 0'],
            'Win Rate This Month' => ['67%', '2 of 3 won · No closed deals in the previous period'],
            'New Deals This Month' => ['2', 'Same as previous period'],
            'New Contacts This Month' => ['1', 'Same as previous period'],
        ]);

        $bob = ($this->kpiStats)(['owner' => (string) $this->bob->id]);

        expect(($this->kpiSummary)($bob))->toBe([
            'Revenue Won This Month' => ['$0.00', '-100% from $900.00'],
            'Deals Won This Month' => ['0', '-100% from 1'],
            'Win Rate This Month' => ['—', 'No deals closed this month'],
            'New Deals This Month' => ['1', '-50% from 2'],
            'New Contacts This Month' => ['1', 'Up from 0'],
        ])
            ->and($bob['Revenue Won This Month']['icon'])->toBe(Heroicon::ArrowTrendingDown)
            ->and($bob['Win Rate This Month']['icon'])->toBeNull();
    });

    test('last month is compared with the month before', function () {
        expect(($this->kpiSummary)(($this->kpiStats)(['period' => 'last_month'])))->toBe([
            'Revenue Won Last Month' => ['$900.00', 'Up from $0.00'],
            'Deals Won Last Month' => ['1', 'Up from 0'],
            'Win Rate Last Month' => ['33%', '1 of 3 won · No closed deals in the previous period'],
            'New Deals Last Month' => ['4', '+100% from 2'],
            'New Contacts Last Month' => ['2', 'Up from 0'],
        ]);

        Livewire::test(KpiOverviewWidget::class, ['pageFilters' => ['period' => 'last_month']])
            ->assertSee('Compared with the previous period, from Aug 1, 2026 to Aug 31, 2026.');
    });

    test('a custom range counts its local days and is compared with the days just before', function () {
        $filters = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-05'];

        expect(($this->kpiStats)($filters)['Deals Won In Period']['value'])->toBe('2');

        Livewire::test(KpiOverviewWidget::class, ['pageFilters' => $filters])
            ->assertSee('Compared with the previous period, from Sep 26, 2026 to Sep 30, 2026.');
    });

    test('a soft-deleted contact is not counted', function () {
        $this->contactC1->delete();

        expect(($this->kpiStats)()['New Contacts This Month']['value'])->toBe('1');
    });

    test('another organization does not leak into the figures', function () {
        $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $globex = $carol->personalOrganization();
        ($this->kpiDeal)(DealStatus::Won, '9000', null, '2026-10-10 10:00:00', '2026-10-10 10:00:00', $globex);
        ($this->kpiContact)(null, '2026-10-10 10:00:00', $globex);

        $stats = ($this->kpiStats)();

        expect($stats['Revenue Won This Month']['value'])->toBe('$1,000.00')
            ->and($stats['Deals Won This Month']['value'])->toBe('2')
            ->and($stats['New Deals This Month']['value'])->toBe('3')
            ->and($stats['New Contacts This Month']['value'])->toBe('2');

        Livewire::test(KpiOverviewWidget::class)->assertDontSee('9,000');
    });

    test('a forged owner shows everyone', function (callable $owner) {
        $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

        $stats = ($this->kpiStats)(['owner' => $owner($carol)]);

        expect($stats['Deals Won This Month']['value'])->toBe('2')
            ->and($stats['New Deals This Month']['value'])->toBe('3');
    })->with([
        'a user of another organization' => [fn (User $carol): string => (string) $carol->id],
        'an injection' => [fn (): string => '1 OR 1=1'],
        'an array' => [fn (): array => ['1']],
    ]);

    test('the figures take four queries, two on deals and two on contacts', function () {
        $widget = Livewire::test(KpiOverviewWidget::class)->instance();

        DB::enableQueryLog();
        invade($widget)->getStats();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        expect($queries->filter(fn (string $sql): bool => str_contains($sql, 'from "deals"')))->toHaveCount(2)
            ->and($queries->filter(fn (string $sql): bool => str_contains($sql, 'from "contacts"')))->toHaveCount(2);
    });

    test('the dashboard hands its filters to the widget', function () {
        $page = Livewire::test(Dashboard::class)->set('filters.owner', 'me')->set('filters.period', 'last_month');

        foreach ([KpiOverviewWidget::class, ContactsOverviewWidget::class] as $widget) {
            $component = $page->instance()->getWidgetsSchemaComponents([$widget])[0];

            expect($component->getData()['pageFilters'])->toMatchArray(['owner' => 'me', 'period' => 'last_month']);
        }
    });
});

test('an organization without deals or contacts shows zeros in its currency', function () {
    $this->org->update(['currency' => Currency::Eur]);
    Filament::setTenant($this->org->refresh());

    $stats = ($this->kpiStats)();

    expect($stats['Revenue Won This Month']['value'])->toBe('€0.00')
        ->and($stats['Revenue Won This Month']['description'])->toBe('Same as previous period')
        ->and($stats['Win Rate This Month']['value'])->toBe('—')
        ->and($stats['Win Rate This Month']['description'])->toBe('No deals closed this month');
});

test('the win rate compares rounded rates in points', function () {
    ($this->kpiDeal)(DealStatus::Won, '1', null, '2026-10-01 10:00:00', '2026-10-05 10:00:00');
    ($this->kpiDeal)(DealStatus::Lost, '1', null, '2026-10-01 10:00:00', '2026-10-06 10:00:00');
    ($this->kpiDeal)(DealStatus::Won, '1', null, '2026-09-01 10:00:00', '2026-09-05 10:00:00');
    ($this->kpiDeal)(DealStatus::Lost, '1', null, '2026-09-01 10:00:00', '2026-09-06 10:00:00');

    $same = ($this->kpiStats)()['Win Rate This Month'];

    expect($same['value'])->toBe('50%')
        ->and($same['description'])->toBe('1 of 2 won · Same as previous period')
        ->and($same['icon'])->toBe(Heroicon::Minus);

    foreach (['2026-09-07 10:00:00', '2026-09-08 10:00:00'] as $wonAt) {
        ($this->kpiDeal)(DealStatus::Won, '1', null, '2026-09-01 10:00:00', $wonAt);
    }

    $down = ($this->kpiStats)()['Win Rate This Month'];

    expect($down['description'])->toBe('1 of 2 won · -25 pts from 75%')
        ->and($down['icon'])->toBe(Heroicon::ArrowTrendingDown)
        ->and($down['color'])->toBe('danger');
});

test('the win rate rounds half up', function () {
    foreach (range(1, 8) as $index) {
        ($this->kpiDeal)($index === 1 ? DealStatus::Won : DealStatus::Lost, '1', null, '2026-10-01 10:00:00', '2026-10-05 10:00:00');
    }

    expect(($this->kpiStats)()['Win Rate This Month']['value'])->toBe('13%');
});

test('the key figures are first, full width and not polled', function () {
    $widget = Livewire::test(KpiOverviewWidget::class)->instance();

    expect(invade($widget)->getPollingInterval())->toBeNull()
        ->and(KpiOverviewWidget::getSort())->toBe(-45)
        ->and($widget->getColumnSpan())->toBe('full')
        ->and(invade($widget)->getColumns())->toBe(['@xl' => 5, '!@lg' => 5]);
});

test('every role sees the key figures', function (OrganizationRole $role) {
    $member = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);

    expect(KpiOverviewWidget::canView())->toBeTrue();

    Livewire::test(KpiOverviewWidget::class)->assertSee('Key figures');
})->with(OrganizationRole::cases());

describe('the contacts overview', function () {
    beforeEach(function () {
        $this->overviewContact = fn (?User $owner, ?ContactStatus $status): Contact => Contact::factory()->create([
            'organization_id' => $this->org->id,
            'owner_id' => $owner?->id,
            'status' => $status,
        ]);

        ($this->overviewContact)($this->user, ContactStatus::Lead);
        ($this->overviewContact)($this->user, ContactStatus::ActiveClient);
        ($this->overviewContact)($this->bob, ContactStatus::Lead);
        ($this->overviewContact)(null, null);
    });

    test('counts every contact without a filter', function () {
        $stats = ($this->kpiStats)([], ContactsOverviewWidget::class);

        expect(array_keys($stats))->toBe(['Total Contacts', 'Active Clients', 'Leads'])
            ->and($stats['Total Contacts']['value'])->toBe('4')
            ->and($stats['Active Clients']['value'])->toBe('1')
            ->and($stats['Leads']['value'])->toBe('2')
            ->and($stats['Total Contacts']['description'])->toBe('All contacts in this organization');
    });

    test('follows the owner filter', function () {
        $stats = ($this->kpiStats)(['owner' => 'me'], ContactsOverviewWidget::class);

        expect($stats['Total Contacts']['value'])->toBe('2')
            ->and($stats['Active Clients']['value'])->toBe('1')
            ->and($stats['Leads']['value'])->toBe('1')
            ->and($stats['Total Contacts']['description'])->toBe('Contacts of the selected owner');
    });

    test('shows everyone for a forged owner', function () {
        expect(($this->kpiStats)(['owner' => '1 OR 1=1'], ContactsOverviewWidget::class)['Total Contacts']['value'])->toBe('4');
    });
});
