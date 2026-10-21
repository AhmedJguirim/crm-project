<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Deals\DealForecast;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->forecastDeal = fn (string $stage, ?string $value, ?string $closeDate, DealStatus $status = DealStatus::Open, ?Organization $org = null): Deal => Deal::factory()->inStage($stage)->create([
        'organization_id' => ($org ?? $this->org)->id,
        'created_by' => $this->user->id,
        'status' => $status,
        'value' => $value,
        'expected_close_date' => $closeDate,
    ]);

    $this->backgroundDeals = function (): void {
        ($this->forecastDeal)('Lead', '1000', '2026-10-25');
        ($this->forecastDeal)('Negotiating', '2000', '2026-10-05');
        ($this->forecastDeal)('Proposal Sent', '4000', '2026-12-15');
        ($this->forecastDeal)('Discovery', null, '2026-11-03');
        ($this->forecastDeal)('Lead', '500', '2026-09-30');
        ($this->forecastDeal)('Negotiating', '800', '2027-04-01');
        ($this->forecastDeal)('Proposal Sent', '600', null);
        ($this->forecastDeal)('Won', '9000', '2026-10-10', DealStatus::Won);
        ($this->forecastDeal)('Lost', '7000', '2026-10-12', DealStatus::Lost);

        $globex = Organization::factory()->create();
        ($this->forecastDeal)('Lead', '5000', '2026-10-15', DealStatus::Open, $globex);
    };
});

test('the service groups open deals by close month', function () {
    ($this->backgroundDeals)();

    $rows = DealForecast::byCloseMonth('2026-10-20');

    expect(array_keys($rows))->toBe(['earlier', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03', 'later', 'none', 'total']);

    $expected = [
        'earlier' => ['Earlier', 1, 500.0, 50.0],
        '2026-10' => ['Oct 2026', 2, 3000.0, 1600.0],
        '2026-11' => ['Nov 2026', 1, 0.0, 0.0],
        '2026-12' => ['Dec 2026', 1, 4000.0, 2000.0],
        '2027-01' => ['Jan 2027', 0, 0.0, 0.0],
        '2027-02' => ['Feb 2027', 0, 0.0, 0.0],
        '2027-03' => ['Mar 2027', 0, 0.0, 0.0],
        'later' => ['Later', 1, 800.0, 600.0],
        'none' => ['No close date', 1, 600.0, 300.0],
        'total' => ['Total', 7, 8900.0, 4550.0],
    ];

    foreach ($expected as $key => [$label, $count, $total, $weighted]) {
        expect($rows[$key])->toBe(['label' => $label, 'deals_count' => $count, 'total' => $total, 'weighted' => $weighted]);
    }
});

test('optional rows are hidden when empty', function () {
    ($this->forecastDeal)('Lead', '1000', '2026-10-25');

    $rows = DealForecast::byCloseMonth('2026-10-20');

    expect(array_keys($rows))->toBe(['2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03', 'total'])
        ->and($rows['total'])->toBe(['label' => 'Total', 'deals_count' => 1, 'total' => 1000.0, 'weighted' => 100.0]);
});

test('without open deals the month rows and total are all zero', function () {
    $rows = DealForecast::byCloseMonth('2026-10-20');

    expect($rows)->toHaveCount(7);

    foreach ($rows as $row) {
        expect($row['deals_count'])->toBe(0)
            ->and($row['total'])->toBe(0.0)
            ->and($row['weighted'])->toBe(0.0);
    }
});

test('the stage current probability is used', function () {
    ($this->forecastDeal)('Lead', '1000', '2026-10-25');
    PipelineStage::query()->where('name', 'Lead')->update(['probability' => 40]);

    expect(DealForecast::byCloseMonth('2026-10-20')['2026-10']['weighted'])->toBe(400.0);
});

test('the window rolls over the year end without overflow', function () {
    expect(array_keys(DealForecast::byCloseMonth('2026-12-31')))->toBe(['2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', 'total']);
    expect(array_keys(DealForecast::byCloseMonth('2026-01-31')))->toBe(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', 'total']);
});

test('a deal closing earlier in the current month stays in the current month', function () {
    ($this->forecastDeal)('Lead', '100', '2026-10-01');
    ($this->forecastDeal)('Lead', '100', '2026-10-31');
    ($this->forecastDeal)('Lead', '100', '2027-03-31');
    ($this->forecastDeal)('Lead', '100', '2027-04-01');

    $rows = DealForecast::byCloseMonth('2026-10-20');

    expect($rows['2026-10']['deals_count'])->toBe(2)
        ->and($rows['2027-03']['deals_count'])->toBe(1)
        ->and($rows['later']['deals_count'])->toBe(1)
        ->and(isset($rows['earlier']))->toBeFalse();
});

test("another organization's open deals are never counted", function () {
    $globex = Organization::factory()->create();
    ($this->forecastDeal)('Lead', '5000', '2026-10-15', DealStatus::Open, $globex);

    $rows = DealForecast::byCloseMonth('2026-10-20');

    expect($rows['total']['deals_count'])->toBe(0)
        ->and($rows['2026-10']['total'])->toBe(0.0);
});

test('an owner id keeps only the deals of that owner, and no owner keeps all of them', function () {
    $bob = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($bob, ['role' => OrganizationRole::Member->value]);

    ($this->forecastDeal)('Lead', '1000', '2026-10-30')->update(['owner_id' => $this->user->id]);
    ($this->forecastDeal)('Negotiating', '2000', '2026-10-30')->update(['owner_id' => $bob->id]);
    ($this->forecastDeal)('Lead', '400', '2026-10-30');

    $mine = DealForecast::byCloseMonth('2026-10-21', 6, $this->user->id);
    $everyone = DealForecast::byCloseMonth('2026-10-21');

    expect($mine['total']['deals_count'])->toBe(1)
        ->and($mine['total']['total'])->toBe(1000.0)
        ->and($mine['2026-10']['deals_count'])->toBe(1)
        ->and($everyone['total']['deals_count'])->toBe(3)
        ->and($everyone['total']['total'])->toBe(3400.0)
        ->and(DealForecast::byCloseMonth('2026-10-21', 6, $bob->id)['total']['total'])->toBe(2000.0);
});
