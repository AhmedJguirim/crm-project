<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Widgets\Reports\SalesResultsWidget;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use App\Services\Reports\DashboardKpis;
use App\Support\Reports\DashboardFilters;
use App\Support\Reports\ReportStats;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Security\SecurityPayloads;

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

    $this->salesDeal = fn (DealStatus $status, ?string $value, ?User $owner, string $createdAt, ?string $closedAt = null, ?Organization $organization = null): Deal => Deal::factory()
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

    $this->salesStats = function (array $pageFilters = []): array {
        $stats = [];

        foreach (invade(Livewire::test(SalesResultsWidget::class, ['pageFilters' => $pageFilters])->instance())->getStats() as $stat) {
            /** @var Stat $stat */
            $stats[(string) $stat->getLabel()] = [
                'value' => (string) $stat->getValue(),
                'description' => (string) $stat->getDescription(),
                'icon' => $stat->getDescriptionIcon(),
                'color' => $stat->getDescriptionColor(),
                'url' => $stat->getUrl(),
            ];
        }

        return $stats;
    };

    $this->salesPeriods = fn (): array => DashboardFilters::fromPageFilters(null)->period->trail(2);
});

afterEach(function () {
    Filament::setTenant(null);
});

describe('with the won and lost deals of two months', function () {
    beforeEach(function () {
        ($this->salesDeal)(DealStatus::Won, '1000', $this->user, '2026-10-01 10:00:00', '2026-10-11 10:00:00');
        ($this->salesDeal)(DealStatus::Won, '2000', $this->bob, '2026-09-11 10:00:00', '2026-10-11 10:00:00');
        ($this->salesDeal)(DealStatus::Won, null, $this->bob, '2026-09-30 10:00:00', '2026-09-30 22:00:00');
        ($this->salesDeal)(DealStatus::Lost, '700', $this->user, '2026-10-02 10:00:00', '2026-10-12 10:00:00');
        ($this->salesDeal)(DealStatus::Lost, '300', null, '2026-10-03 10:00:00', '2026-10-20 10:00:00');
        ($this->salesDeal)(DealStatus::Won, '500', $this->user, '2026-09-01 10:00:00', '2026-09-30 21:59:59');
        ($this->salesDeal)(DealStatus::Lost, '400', $this->bob, '2026-09-02 10:00:00', '2026-09-15 10:00:00');
        ($this->salesDeal)(DealStatus::Lost, '100', $this->user, '2026-09-03 10:00:00', '2026-09-20 10:00:00');
        ($this->salesDeal)(DealStatus::Open, '800', $this->user, '2026-10-15 10:00:00');

        $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        ($this->salesDeal)(DealStatus::Won, '9000', null, '2026-10-01 10:00:00', '2026-10-10 10:00:00', $carol->personalOrganization());
        ($this->salesDeal)(DealStatus::Lost, '9000', null, '2026-10-01 10:00:00', '2026-10-10 10:00:00', $carol->personalOrganization());
    });

    test('this month for everyone is compared with the previous month', function () {
        $stats = ($this->salesStats)();

        expect(array_map(fn (array $stat): array => [$stat['value'], $stat['description'], $stat['icon'], $stat['color']], $stats))->toBe([
            'Deals Won This Month' => ['3', '+200% from 1', Heroicon::ArrowTrendingUp, 'success'],
            'Revenue Won This Month' => ['$3,000.00', '+500% from $500.00', Heroicon::ArrowTrendingUp, 'success'],
            'Average Won Deal This Month' => ['$1,000.00', '+100% from $500.00', Heroicon::ArrowTrendingUp, 'success'],
            'Average Sales Cycle This Month' => ['14 days', '-51.7% from 29 days', Heroicon::ArrowTrendingDown, 'success'],
            'Deals Lost This Month' => ['2', 'Same as previous period', Heroicon::Minus, 'gray'],
            'Lost Value This Month' => ['$1,000.00', '+100% from $500.00', Heroicon::ArrowTrendingUp, 'danger'],
            'Win Rate This Month' => ['60%', '3 of 5 won · +27 pts from 33%', Heroicon::ArrowTrendingUp, 'success'],
        ]);

        Livewire::test(SalesResultsWidget::class)
            ->assertSee('Sales results')
            ->assertSee('Deals won or lost this month, compared with the previous period, from Sep 1, 2026 to Sep 30, 2026. Deals reopened since are not counted.');
    });

    test('the won and lost figures link to the deals list filtered by status', function () {
        $stats = ($this->salesStats)();
        $won = DealResource::getUrl('index').'?tableFilters[status][value]=won';
        $lost = DealResource::getUrl('index').'?tableFilters[status][value]=lost';

        expect($stats['Deals Won This Month']['url'])->toBe($won)
            ->and($stats['Revenue Won This Month']['url'])->toBe($won)
            ->and($stats['Deals Lost This Month']['url'])->toBe($lost)
            ->and($stats['Lost Value This Month']['url'])->toBe($lost)
            ->and($stats['Average Won Deal This Month']['url'])->toBeNull();
    });

    test('the owner filter keeps one member and says so when the previous period had no won deal', function () {
        $stats = ($this->salesStats)(['owner' => (string) $this->bob->id]);

        expect($stats['Deals Won This Month']['value'])->toBe('2')
            ->and($stats['Deals Won This Month']['description'])->toBe('Up from 0')
            ->and($stats['Revenue Won This Month']['value'])->toBe('$2,000.00')
            ->and($stats['Revenue Won This Month']['description'])->toBe('Up from $0.00')
            ->and($stats['Average Won Deal This Month']['value'])->toBe('$1,000.00')
            ->and($stats['Average Won Deal This Month']['description'])->toBe('No won deals in the previous period')
            ->and($stats['Average Won Deal This Month']['color'])->toBe('gray')
            ->and($stats['Average Sales Cycle This Month']['value'])->toBe('15 days')
            ->and($stats['Average Sales Cycle This Month']['description'])->toBe('No won deals in the previous period')
            ->and($stats['Average Sales Cycle This Month']['color'])->toBe('gray')
            ->and($stats['Deals Lost This Month']['value'])->toBe('0')
            ->and($stats['Deals Lost This Month']['description'])->toBe('-100% from 1')
            ->and($stats['Deals Lost This Month']['color'])->toBe('success')
            ->and($stats['Lost Value This Month']['value'])->toBe('$0.00')
            ->and($stats['Lost Value This Month']['description'])->toBe('-100% from $400.00')
            ->and($stats['Lost Value This Month']['color'])->toBe('success')
            ->and($stats['Win Rate This Month']['value'])->toBe('100%')
            ->and($stats['Win Rate This Month']['description'])->toBe('2 of 2 won · +100 pts from 0%');
    });

    test('a period without closed deals shows dashes', function () {
        $stats = ($this->salesStats)(['period' => 'today']);

        expect($stats['Deals Won Today']['value'])->toBe('0')
            ->and($stats['Deals Won Today']['description'])->toBe('Same as previous period')
            ->and($stats['Average Won Deal Today']['value'])->toBe('—')
            ->and($stats['Average Won Deal Today']['description'])->toBe('No deals won today')
            ->and($stats['Average Sales Cycle Today']['value'])->toBe('—')
            ->and($stats['Average Sales Cycle Today']['description'])->toBe('No deals won today')
            ->and($stats['Win Rate Today']['value'])->toBe('—')
            ->and($stats['Win Rate Today']['description'])->toBe('No deals closed today');
    });

    test('the service runs one query, sums per period and ignores other organizations', function () {
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $rows = DashboardKpis::salesResultsByPeriod(($this->salesPeriods)(), null);

        expect($queries)->toHaveCount(1)
            ->and($rows)->toHaveCount(2)
            ->and($rows[1])->toBe([
                'won_count' => 3,
                'won_value' => 3000.0,
                'won_cycle_seconds' => 3499200.0,
                'lost_count' => 2,
                'lost_value' => 1000.0,
            ])
            ->and($rows[0])->toBe([
                'won_count' => 1,
                'won_value' => 500.0,
                'won_cycle_seconds' => 2548799.0,
                'lost_count' => 2,
                'lost_value' => 500.0,
            ]);
    });

    test('the service keeps a single owner', function () {
        $rows = DashboardKpis::salesResultsByPeriod(($this->salesPeriods)(), $this->user->id);

        expect($rows[1]['won_count'])->toBe(1)
            ->and($rows[1]['won_value'])->toBe(1000.0)
            ->and($rows[1]['lost_count'])->toBe(1)
            ->and($rows[1]['lost_value'])->toBe(700.0);
    });

    test('forged filters reach no SQL text', function (string $payload) {
        $filters = ['period' => 'custom', 'from' => $payload, 'to' => $payload, 'owner' => $payload];

        $sql = SecurityPayloads::queriesDuring(fn () => Livewire::test(SalesResultsWidget::class, ['pageFilters' => $filters])->assertSuccessful());

        SecurityPayloads::assertSqlIsClean($sql, $payload);
        expect($sql)->not->toBeEmpty();
    })->with(SecurityPayloads::sql());

    test('an owner of another organization is ignored', function () {
        $carol = User::factory()->onboardingCompleted()->create();

        $stats = ($this->salesStats)(['owner' => (string) $carol->id]);

        expect($stats['Deals Won This Month']['value'])->toBe('3');
    });
});

test('a deal won before it was created counts as 0 days', function () {
    ($this->salesDeal)(DealStatus::Won, '100', $this->user, '2026-10-15 10:00:00', '2026-10-14 10:00:00');

    $stats = ($this->salesStats)();

    expect($stats['Average Sales Cycle This Month']['value'])->toBe('0 days')
        ->and(DashboardKpis::salesResultsByPeriod(($this->salesPeriods)(), null)[1]['won_cycle_seconds'])->toBe(0.0);
});

test('a one day cycle is singular', function () {
    ($this->salesDeal)(DealStatus::Won, '100', $this->user, '2026-10-14 10:00:00', '2026-10-15 10:00:00');

    expect(($this->salesStats)()['Average Sales Cycle This Month']['value'])->toBe('1 day');
});

test('a reopened deal is counted nowhere', function () {
    ($this->salesDeal)(DealStatus::Open, '100', $this->user, '2026-10-01 10:00:00')->forceFill(['won_at' => '2026-10-05 10:00:00', 'lost_at' => '2026-10-06 10:00:00'])->save();

    $stats = ($this->salesStats)();

    expect($stats['Deals Won This Month']['value'])->toBe('0')
        ->and($stats['Deals Lost This Month']['value'])->toBe('0')
        ->and($stats['Win Rate This Month']['value'])->toBe('—');
});

test('every role sees the figures', function (OrganizationRole $role) {
    ($this->salesDeal)(DealStatus::Won, '100', $this->user, '2026-10-01 10:00:00', '2026-10-05 10:00:00');
    $member = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);

    expect(($this->salesStats)()['Deals Won This Month']['value'])->toBe('1');

    Livewire::test(SalesResultsWidget::class)->assertSee('Sales results');
})->with(OrganizationRole::cases());

test('the widget is not on the dashboard', function () {
    expect(Livewire::test(Dashboard::class)->instance()->getWidgets())->not->toContain(SalesResultsWidget::class);

    $this->get(Dashboard::getUrl(tenant: $this->org))->assertOk()->assertDontSee('Sales results');
});

test('a lower figure is the better one when higherIsBetter is false', function (int $current, int $previous, string $description, Heroicon $icon, string $color) {
    $stat = ReportStats::compared(Stat::make('x', '1'), $current, $previous, (string) $previous, higherIsBetter: false);

    expect($stat->getDescription())->toBe($description)
        ->and($stat->getDescriptionIcon())->toBe($icon)
        ->and($stat->getDescriptionColor())->toBe($color);
})->with([
    'down from 10' => [5, 10, '-50% from 10', Heroicon::ArrowTrendingDown, 'success'],
    'up from 5' => [10, 5, '+100% from 5', Heroicon::ArrowTrendingUp, 'danger'],
    'up from 0' => [3, 0, 'Up from 0', Heroicon::ArrowTrendingUp, 'danger'],
    'same' => [4, 4, 'Same as previous period', Heroicon::Minus, 'gray'],
]);
