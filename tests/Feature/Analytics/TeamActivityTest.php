<?php

use App\Enums\ActivityType;
use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Enums\ReportPeriodPreset;
use App\Models\User;
use App\Services\Reports\DashboardKpis;
use App\Services\Reports\TeamActivity;
use App\Support\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Analytics\TeamActivityFixture;

beforeEach(function () {
    $this->travelTo(TeamActivityFixture::now());
    $this->team = TeamActivityFixture::build();
    $this->thisMonth = fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth);
    $this->lastMonth = fn (): ReportPeriod => ReportPeriod::forPreset(ReportPeriodPreset::LastMonth);
});

test('rows, order and total for this month', function () {
    $rows = TeamActivity::forPeriod(($this->thisMonth)(), null);

    expect(array_keys($rows))->toBe(["user-{$this->team->bob->id}", "user-{$this->team->alice->id}", "user-{$this->team->fred->id}", 'total'])
        ->and($rows["user-{$this->team->bob->id}"])->toBe([
            'member' => 'Bob Brown', 'is_total' => false, 'activities' => 1, 'activities_breakdown' => 'WhatsApp 1',
            'tasks_completed' => 1, 'tasks_overdue' => 0, 'deals_created' => 1, 'deals_won' => 1, 'won_value' => 2000.0,
            'deals_lost' => 0, 'win_rate' => '100% (1 of 1)',
        ])
        ->and($rows["user-{$this->team->alice->id}"])->toBe([
            'member' => 'Alice Anders', 'is_total' => false, 'activities' => 4, 'activities_breakdown' => 'Call 2 · Email 1 · Meeting 1',
            'tasks_completed' => 1, 'tasks_overdue' => 1, 'deals_created' => 3, 'deals_won' => 2, 'won_value' => 1500.0,
            'deals_lost' => 1, 'win_rate' => '67% (2 of 3)',
        ])
        ->and($rows["user-{$this->team->fred->id}"])->toBe([
            'member' => 'Fred Former (no longer a member)', 'is_total' => false, 'activities' => 0, 'activities_breakdown' => '',
            'tasks_completed' => 0, 'tasks_overdue' => 0, 'deals_created' => 0, 'deals_won' => 1, 'won_value' => 300.0,
            'deals_lost' => 0, 'win_rate' => '100% (1 of 1)',
        ])
        ->and($rows['total'])->toBe([
            'member' => 'Total', 'is_total' => true, 'activities' => 5, 'activities_breakdown' => 'Call 2 · Email 1 · Meeting 1 · WhatsApp 1',
            'tasks_completed' => 2, 'tasks_overdue' => 1, 'deals_created' => 4, 'deals_won' => 4, 'won_value' => 3800.0,
            'deals_lost' => 1, 'win_rate' => '80% (4 of 5)',
        ])
        ->and(array_key_exists("user-{$this->team->vera->id}", $rows))->toBeFalse();
});

test('last month', function () {
    $rows = TeamActivity::forPeriod(($this->lastMonth)(), null);

    expect(array_keys($rows))->toBe(["user-{$this->team->alice->id}", "user-{$this->team->bob->id}", "user-{$this->team->fred->id}", 'total'])
        ->and($rows["user-{$this->team->alice->id}"])->toMatchArray([
            'activities' => 1, 'activities_breakdown' => 'Call 1', 'tasks_completed' => 1, 'tasks_overdue' => 1,
            'deals_created' => 1, 'deals_won' => 0, 'won_value' => 0.0, 'win_rate' => '—',
        ])
        ->and($rows["user-{$this->team->bob->id}"])->toMatchArray([
            'activities' => 0, 'activities_breakdown' => '', 'tasks_completed' => 0, 'tasks_overdue' => 0,
            'deals_created' => 0, 'deals_won' => 0, 'won_value' => 0.0, 'deals_lost' => 0, 'win_rate' => '—',
        ])
        ->and($rows["user-{$this->team->fred->id}"])->toMatchArray([
            'member' => 'Fred Former (no longer a member)', 'deals_created' => 1, 'deals_won' => 0, 'win_rate' => '—',
        ])
        ->and($rows['total'])->toMatchArray([
            'activities' => 1, 'activities_breakdown' => 'Call 1', 'tasks_completed' => 1, 'tasks_overdue' => 1,
            'deals_created' => 2, 'deals_won' => 0, 'win_rate' => '—',
        ]);
});

test('the owner filter keeps one member and the total', function () {
    $rows = TeamActivity::forPeriod(($this->thisMonth)(), $this->team->bob->id);

    expect(array_keys($rows))->toBe(["user-{$this->team->bob->id}", 'total'])
        ->and($rows['total'])->toBe(['member' => 'Total', 'is_total' => true] + array_slice($rows["user-{$this->team->bob->id}"], 2));
});

test('a viewer with a figure gets a row and members with nothing still do', function () {
    $this->team->activity($this->team->vera, ActivityType::Note, '2026-10-09 09:00:00');
    $ann = $this->team->member('Ann Archer', OrganizationRole::Member);

    $rows = TeamActivity::forPeriod(($this->thisMonth)(), null);

    expect($rows["user-{$this->team->vera->id}"])->toMatchArray(['activities' => 1, 'activities_breakdown' => 'Note 1'])
        ->and($rows["user-{$ann->id}"])->toMatchArray(['member' => 'Ann Archer', 'activities' => 0, 'deals_won' => 0, 'win_rate' => '—']);

    $keys = array_keys($rows);

    expect(array_search("user-{$ann->id}", $keys))->toBeLessThan(array_search("user-{$this->team->vera->id}", $keys));
});

test('ties on won value sort by deals won then name', function () {
    $zed = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Zed Zane']);
    $tie = $zed->personalOrganization();
    $tie->update(['timezone' => 'UTC']);
    Filament::setTenant($tie->refresh());

    [$ann, $dan, $abe, $eve] = array_map(fn (string $name): User => $this->team->member($name, OrganizationRole::Member, $tie), ['Ann Archer', 'Dan Dent', 'Abe Adams', 'Eve Evans']);

    $this->team->deal(DealStatus::Won, '1000', $ann, '2026-10-01 10:00:00', '2026-10-10 10:00:00', $tie);
    $this->team->deal(DealStatus::Won, '1000', $dan, '2026-10-01 10:00:00', '2026-10-10 10:00:00', $tie);
    $this->team->deal(DealStatus::Won, null, $dan, '2026-10-01 10:00:00', '2026-10-11 10:00:00', $tie);

    $keys = array_keys(TeamActivity::forPeriod(($this->thisMonth)(), null));

    expect($keys)->toBe(["user-{$dan->id}", "user-{$ann->id}", "user-{$abe->id}", "user-{$eve->id}", "user-{$zed->id}", 'total']);
});

test('a member figures match the KPI row', function () {
    $period = ($this->thisMonth)();
    $kpi = DashboardKpis::dealFiguresByPeriod([$period], $this->team->alice->id)[0];
    $row = TeamActivity::forPeriod($period, null)["user-{$this->team->alice->id}"];

    expect($row['deals_won'])->toBe($kpi['won_count'])
        ->and($row['won_value'])->toBe($kpi['won_value'])
        ->and($row['deals_lost'])->toBe($kpi['lost_count'])
        ->and($row['deals_created'])->toBe($kpi['created_count']);
});

test('it runs at most five queries', function () {
    $period = ($this->thisMonth)();
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    TeamActivity::forPeriod($period, null);

    expect($count)->toBeLessThanOrEqual(5);
});

test('another organization is never counted', function () {
    $rows = TeamActivity::forPeriod(($this->thisMonth)(), null);

    expect($rows['total']['won_value'])->toBe(3800.0)
        ->and(array_key_exists("user-{$this->team->carol->id}", $rows))->toBeFalse();

    Filament::setTenant($this->team->globex);

    $globex = TeamActivity::forPeriod(($this->thisMonth)(), null);

    expect(array_keys($globex))->toBe(["user-{$this->team->carol->id}", 'total'])
        ->and($globex['total'])->toMatchArray(['won_value' => 9000.0, 'activities' => 1, 'deals_won' => 1]);
});
