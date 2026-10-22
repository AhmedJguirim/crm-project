<?php

use App\Enums\DealStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Reports\SalesVelocity;
use App\Support\Reports\DashboardFilters;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Tests\Feature\Analytics\SalesVelocityFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['timezone' => 'Europe/Paris']);
    $this->actingAs($this->user);
    Filament::setTenant($this->org->refresh());

    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Brown']);
    $this->period = DashboardFilters::fromPageFilters(null)->period;
});

afterEach(function () {
    Filament::setTenant(null);
});

describe('with the won, lost and open deals of two months', function () {
    beforeEach(function () {
        $this->org->members()->attach($this->bob, ['role' => 'member']);
        SalesVelocityFixture::build($this->org, $this->user, $this->bob, Organization::factory()->create());
    });

    test('this month, everyone', function () {
        $velocity = SalesVelocity::forPeriod($this->period, null);

        expect($velocity)->toMatchArray([
            'open_deals' => 3,
            'won_count' => 2,
            'closed_count' => 3,
            'average_won_value' => 1500.0,
            'cycle_days' => 20.0,
        ])
            ->and($velocity['win_rate'])->toEqualWithDelta(2 / 3, 0.000001)
            ->and($velocity['per_day'])->toEqualWithDelta(150.0, 0.000001);
    });

    test('the owner filter applies to the open and the closed deals', function () {
        $velocity = SalesVelocity::forPeriod($this->period, $this->bob->id);

        expect($velocity)->toMatchArray([
            'open_deals' => 1,
            'won_count' => 1,
            'closed_count' => 1,
            'average_won_value' => 2000.0,
            'win_rate' => 1.0,
            'cycle_days' => 30.0,
        ])
            ->and($velocity['per_day'])->toEqualWithDelta(66.6667, 0.001);
    });

    test('the open deals are counted whatever their age, for the owner filtered', function () {
        $velocity = SalesVelocity::forPeriod($this->period, $this->user->id);

        expect($velocity['open_deals'])->toBe(2)
            ->and($velocity['won_count'])->toBe(1)
            ->and($velocity['closed_count'])->toBe(2);
    });

    test('there is no velocity without a won deal in the period', function () {
        expect(SalesVelocity::forPeriod(DashboardFilters::fromPageFilters(['period' => 'last_month'])->period, null))->toBeNull();
    });

    test('an owner of another organization has no velocity', function () {
        $carol = User::factory()->onboardingCompleted()->create();

        expect(SalesVelocity::forPeriod($this->period, $carol->id))->toBeNull();
    });
});

test('a cycle under one day counts as one day', function () {
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Won, '600', $this->user, '2026-10-20 10:00:00', '2026-10-20 16:00:00');
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Open, '10', $this->user, '2026-10-20 10:00:00');

    $velocity = SalesVelocity::forPeriod($this->period, null);

    expect($velocity['cycle_days'])->toBe(1.0)
        ->and($velocity['per_day'])->toBe(600.0);
});

test('a deal won before it was created counts as a one day cycle', function () {
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Won, '600', $this->user, '2026-10-15 10:00:00', '2026-10-14 10:00:00');
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Open, '10', $this->user, '2026-10-20 10:00:00');

    expect(SalesVelocity::forPeriod($this->period, null)['cycle_days'])->toBe(1.0);
});

test('without an open deal the velocity is zero', function () {
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Won, '1000', $this->user, '2026-10-01 10:00:00', '2026-10-11 10:00:00');

    $velocity = SalesVelocity::forPeriod($this->period, null);

    expect($velocity['open_deals'])->toBe(0)
        ->and($velocity['per_day'])->toBe(0.0)
        ->and($velocity['average_won_value'])->toBe(1000.0);
});

test('the figures are computed from the unrounded inputs', function () {
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Won, '100', $this->user, '2026-10-01 10:00:00', '2026-10-03 10:00:00');
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Won, '101', $this->user, '2026-10-01 10:00:00', '2026-10-04 10:00:00');
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Lost, '5', $this->user, '2026-10-01 10:00:00', '2026-10-05 10:00:00');
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Open, '10', $this->user, '2026-10-20 10:00:00');

    $velocity = SalesVelocity::forPeriod($this->period, null);

    expect($velocity['per_day'])->toEqualWithDelta(1 * 100.5 * (2 / 3) / 2.5, 0.000001);
});
