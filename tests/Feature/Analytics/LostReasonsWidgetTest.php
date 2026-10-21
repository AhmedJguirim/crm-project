<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Reports\LostReasonsWidget;
use App\Filament\Widgets\Reports\SalesResultsWidget;
use App\Models\Deal;
use App\Models\LostReason;
use App\Models\Organization;
use App\Models\User;
use App\Services\Reports\DashboardKpis;
use App\Support\Reports\DashboardFilters;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
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

    $this->reasons = [];

    foreach (['Price too high', 'No budget', 'Went with a competitor', 'Bad timing'] as $name) {
        $this->reasons[$name] = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => $name]);
    }

    $this->lostDeal = fn (?LostReason $reason, string $lostAt, ?User $owner = null, ?Organization $organization = null): Deal => Deal::factory()
        ->inStage('Lost')
        ->create([
            'organization_id' => ($organization ?? $this->org)->id,
            'created_by' => $this->user->id,
            'owner_id' => ($owner ?? $this->user)->id,
            'status' => DealStatus::Lost,
            'lost_reason_id' => $reason?->id,
            'lost_at' => $lostAt,
            'created_at' => '2026-01-01 10:00:00',
        ]);

    $this->chartData = fn (array $pageFilters = []): array => invade(Livewire::test(LostReasonsWidget::class, ['pageFilters' => $pageFilters])->instance())->getData();

    $this->lostPeriods = fn (): array => DashboardFilters::fromPageFilters(null)->period->trail(2);
});

afterEach(function () {
    Filament::setTenant(null);
});

describe('with the lost deals of three months', function () {
    beforeEach(function () {
        $r = $this->reasons;

        ($this->lostDeal)($r['Price too high'], '2026-10-05 10:00:00');
        ($this->lostDeal)($r['Price too high'], '2026-10-12 10:00:00', $this->bob);
        ($this->lostDeal)($r['Bad timing'], '2026-10-08 10:00:00');
        ($this->lostDeal)(null, '2026-10-15 10:00:00');
        ($this->lostDeal)($r['No budget'], '2026-09-30 22:30:00');
        ($this->lostDeal)($r['No budget'], '2026-09-10 10:00:00');
        ($this->lostDeal)($r['No budget'], '2026-09-20 10:00:00');
        ($this->lostDeal)($r['Price too high'], '2026-09-25 10:00:00');
        ($this->lostDeal)(null, '2026-09-30 21:30:00', $this->bob);
        ($this->lostDeal)($r['Price too high'], '2026-08-20 10:00:00');

        $r['Bad timing']->delete();

        Deal::factory()->inStage('Won')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id, 'owner_id' => $this->user->id, 'status' => DealStatus::Won, 'won_at' => '2026-10-10 10:00:00']);
        Deal::factory()->inStage('Lead')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id, 'owner_id' => $this->user->id, 'created_at' => '2026-10-10 10:00:00']);

        $reopened = ($this->lostDeal)($r['No budget'], '2026-10-09 10:00:00');
        $reopened->update(['status' => DealStatus::Open, 'pipeline_stage_id' => Deal::factory()->inStage('Lead')->make(['organization_id' => $this->org->id])->pipeline_stage_id]);
        $reopened->forceFill(['lost_at' => '2026-10-09 10:00:00'])->save();

        $globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $other = $globex->personalOrganization();
        ($this->lostDeal)(LostReason::factory()->create(['organization_id' => $other->id, 'name' => 'Globex reason']), '2026-10-10 10:00:00', $globex, $other);
    });

    test('this month for everyone is compared with the previous month', function () {
        $data = ($this->chartData)();

        expect($data['labels'])->toBe(['Price too high', 'No budget', 'Bad timing (deleted)', 'No reason given'])
            ->and($data['datasets'])->toHaveCount(2)
            ->and($data['datasets'][0])->toBe(['label' => 'Deals Lost This Month', 'data' => [2, 1, 1, 1], 'backgroundColor' => '#dc2626', 'borderColor' => '#dc2626'])
            ->and($data['datasets'][1])->toBe(['label' => 'Previous Period', 'data' => [1, 2, 0, 1], 'backgroundColor' => '#6b7280', 'borderColor' => '#6b7280'])
            ->and($data['labels'])->not->toContain('Went with a competitor')
            ->and($data['labels'])->not->toContain('Globex reason');

        Livewire::test(LostReasonsWidget::class)
            ->assertSee('Lost reasons')
            ->assertSee('Deals lost this month by reason, compared with the previous period, from Sep 1, 2026 to Sep 30, 2026. Deals reopened since are not counted.');
    });

    test('the bars add up to the Deals Lost stat', function () {
        $data = ($this->chartData)();
        $stats = invade(Livewire::test(SalesResultsWidget::class)->instance())->getStats();
        $lost = collect($stats)->first(fn ($stat): bool => (string) $stat->getLabel() === 'Deals Lost This Month');
        $figures = DashboardKpis::salesResultsByPeriod(($this->lostPeriods)(), null);

        expect(array_sum($data['datasets'][0]['data']))->toBe(5)
            ->and((string) $lost->getValue())->toBe('5')
            ->and(array_sum($data['datasets'][1]['data']))->toBe(4)
            ->and($figures[0]['lost_count'])->toBe(4);
    });

    test('last month is compared with the month before', function () {
        $data = ($this->chartData)(['period' => 'last_month']);

        expect($data['labels'])->toBe(['No budget', 'Price too high', 'No reason given'])
            ->and($data['datasets'][0]['label'])->toBe('Deals Lost Last Month')
            ->and($data['datasets'][0]['data'])->toBe([2, 1, 1])
            ->and($data['datasets'][1]['data'])->toBe([0, 1, 0]);
    });

    test('the owner filter keeps one member', function () {
        $data = ($this->chartData)(['owner' => (string) $this->bob->id]);

        expect($data['labels'])->toBe(['Price too high', 'No reason given'])
            ->and($data['datasets'][0]['data'])->toBe([1, 0])
            ->and($data['datasets'][1]['data'])->toBe([0, 1]);
    });

    test('an owner of another organization is ignored', function () {
        $carol = User::factory()->onboardingCompleted()->create();

        expect(($this->chartData)(['owner' => (string) $carol->id]))->toBe(($this->chartData)());
    });

    test('the service counts with one query and ignores other organizations', function () {
        $r = $this->reasons;
        $result = null;

        $sql = SecurityPayloads::queriesDuring(function () use (&$result): void {
            $result = DashboardKpis::lostReasonCountsByPeriod(($this->lostPeriods)(), null);
        });

        expect($sql)->toHaveCount(1)
            ->and($result)->toEqual([
                ['by_reason' => [$r['Price too high']->id => 1, $r['No budget']->id => 2], 'no_reason' => 1],
                ['by_reason' => [$r['Price too high']->id => 2, $r['Bad timing']->id => 1, $r['No budget']->id => 1], 'no_reason' => 1],
            ]);
    });

    test('forged filters reach no SQL text', function (string $payload) {
        $filters = ['period' => 'custom', 'from' => $payload, 'to' => $payload, 'owner' => $payload];

        $sql = SecurityPayloads::queriesDuring(fn () => Livewire::test(LostReasonsWidget::class, ['pageFilters' => $filters])->assertSuccessful());

        SecurityPayloads::assertSqlIsClean($sql, $payload);
        expect($sql)->not->toBeEmpty();
    })->with(SecurityPayloads::sql());
});

test('nothing lost in either period shows the empty state', function () {
    ($this->lostDeal)($this->reasons['Price too high'], '2026-08-20 10:00:00');

    expect(($this->chartData)())->toBe([]);

    Livewire::test(LostReasonsWidget::class)
        ->assertSee('No lost deals')
        ->assertSee('No deals were lost this month or in the previous period.');
});

test('deals without a reason alone are one bar and run no lost reason query', function () {
    ($this->lostDeal)(null, '2026-10-15 10:00:00');
    $data = null;

    $sql = SecurityPayloads::queriesDuring(function () use (&$data): void {
        $data = ($this->chartData)();
    });

    expect($data['labels'])->toBe(['No reason given'])
        ->and($data['datasets'][0]['data'])->toBe([1])
        ->and($data['datasets'][1]['data'])->toBe([0])
        ->and(collect($sql)->filter(fn (string $statement): bool => str_contains($statement, '"lost_reasons"')))->toBeEmpty();
});

test('two deleted reasons with the same name stay two bars and ties sort by previous count then name', function () {
    $first = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'Dup']);
    $first->delete();
    $second = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'Dup']);
    $second->delete();
    $zeta = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'Zeta']);
    $alpha = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => 'Alpha']);

    ($this->lostDeal)($first, '2026-10-05 10:00:00');
    ($this->lostDeal)($second, '2026-10-05 10:00:00');
    ($this->lostDeal)($second, '2026-09-05 10:00:00');
    ($this->lostDeal)($zeta, '2026-10-05 10:00:00');
    ($this->lostDeal)($alpha, '2026-10-05 10:00:00');

    $data = ($this->chartData)();

    expect($data['labels'])->toBe(['Dup (deleted)', 'Alpha', 'Dup (deleted)', 'Zeta'])
        ->and($data['datasets'][0]['data'])->toBe([1, 1, 1, 1])
        ->and($data['datasets'][1]['data'])->toBe([1, 0, 0, 0]);
});

test('a reason name with markup stays inert', function (string $payload) {
    $reason = LostReason::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
    ($this->lostDeal)($reason, '2026-10-05 10:00:00');

    expect(($this->chartData)()['labels'])->toBe([$payload]);

    Livewire::test(LostReasonsWidget::class)->assertDontSee($payload, false);
})->with(SecurityPayloads::xss());

test('every role sees the chart', function (OrganizationRole $role) {
    $member = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);

    Livewire::test(LostReasonsWidget::class)->assertSee('Lost reasons');
})->with(OrganizationRole::cases());

test('the chart is a horizontal bar chart that is not polled', function () {
    $widget = Livewire::test(LostReasonsWidget::class)->instance();

    expect(invade($widget)->getType())->toBe('bar')
        ->and(invade($widget)->getOptions())->toBe(['indexAxis' => 'y', 'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]])
        ->and(invade($widget)->getPollingInterval())->toBeNull()
        ->and(Livewire::test(Dashboard::class)->instance()->getWidgets())->not->toContain(LostReasonsWidget::class);

    $this->get(Dashboard::getUrl(tenant: $this->org))->assertOk()->assertDontSeeLivewire(LostReasonsWidget::class);
});
