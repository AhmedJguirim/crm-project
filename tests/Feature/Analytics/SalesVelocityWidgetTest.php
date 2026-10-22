<?php

use App\Enums\DealStatus;
use App\Enums\OrganizationRole;
use App\Filament\Widgets\Reports\SalesVelocityWidget;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Livewire;
use Tests\Feature\Analytics\SalesVelocityFixture;
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

    $this->velocityStats = function (array $pageFilters = []): array {
        $stats = [];

        foreach (invade(Livewire::test(SalesVelocityWidget::class, ['pageFilters' => $pageFilters])->instance())->getStats() as $stat) {
            /** @var Stat $stat */
            $stats[(string) $stat->getLabel()] = [
                'value' => (string) $stat->getValue(),
                'description' => (string) $stat->getDescription(),
                'color' => $stat->getDescriptionColor(),
                'icon' => $stat->getDescriptionIcon(),
                'url' => $stat->getUrl(),
            ];
        }

        return $stats;
    };
});

afterEach(function () {
    Filament::setTenant(null);
});

describe('with the won, lost and open deals of two months', function () {
    beforeEach(function () {
        SalesVelocityFixture::build($this->org, $this->user, $this->bob, Organization::factory()->create());
    });

    test('the widget shows the heading, the description and one stat', function () {
        Livewire::test(SalesVelocityWidget::class, ['pageFilters' => null])
            ->assertSee('Sales velocity')
            ->assertSee('What the open deals are expected to bring per day: open deals now × average won deal × win rate ÷ average sales cycle, from the deals won or lost this month.');

        $stats = ($this->velocityStats)();

        expect($stats)->toHaveCount(1)->toHaveKey('Sales Velocity This Month')
            ->and($stats['Sales Velocity This Month'])->toBe([
                'value' => '$150.00 per day',
                'description' => '3 open deals × $1,500.00 average won deal × 67% win rate (2 of 3) ÷ 20 days average cycle',
                'color' => 'gray',
                'icon' => null,
                'url' => null,
            ]);
    });

    test('the widget follows the owner filter', function () {
        $stat = ($this->velocityStats)(['owner' => (string) $this->bob->id])['Sales Velocity This Month'];

        expect($stat['value'])->toBe('$66.67 per day')
            ->and($stat['description'])->toBe('1 open deal × $2,000.00 average won deal × 100% win rate (1 of 1) ÷ 30 days average cycle');
    });

    test('a period without a won deal shows a dash', function () {
        $stat = ($this->velocityStats)(['period' => 'last_month'])['Sales Velocity Last Month'];

        expect($stat['value'])->toBe('—')
            ->and($stat['description'])->toBe('No deals won last month, so there is no velocity to show');
    });

    test('every role sees the stat', function (OrganizationRole $role) {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        expect(($this->velocityStats)())->toHaveKey('Sales Velocity This Month');
        Livewire::test(SalesVelocityWidget::class, ['pageFilters' => null])->assertSee('Sales Velocity This Month')->assertSee('$150.00 per day');
    })->with([OrganizationRole::Owner, OrganizationRole::Admin, OrganizationRole::Member, OrganizationRole::Viewer]);

    test('forged filters reach no SQL text', function (string $payload) {
        $filters = ['period' => 'custom', 'from' => $payload, 'to' => $payload, 'owner' => $payload];

        $sql = SecurityPayloads::queriesDuring(fn () => Livewire::test(SalesVelocityWidget::class, ['pageFilters' => $filters])->assertSuccessful());

        SecurityPayloads::assertSqlIsClean($sql, $payload);
        expect($sql)->not->toBeEmpty();
    })->with(SecurityPayloads::sql());
});

test('a one day cycle is singular', function () {
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Won, '600', $this->user, '2026-10-20 10:00:00', '2026-10-20 16:00:00');
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Open, '10', $this->user, '2026-10-20 10:00:00');

    $stat = ($this->velocityStats)()['Sales Velocity This Month'];

    expect($stat['description'])->toEndWith('÷ 1 day average cycle')
        ->and($stat['value'])->toBe('$600.00 per day');
});

test('without an open deal the velocity is zero', function () {
    SalesVelocityFixture::deal($this->org, $this->user, DealStatus::Won, '1000', $this->user, '2026-10-01 10:00:00', '2026-10-11 10:00:00');

    $stat = ($this->velocityStats)()['Sales Velocity This Month'];

    expect($stat['value'])->toBe('$0.00 per day')
        ->and($stat['description'])->toBe('0 open deals × $1,000.00 average won deal × 100% win rate (1 of 1) ÷ 10 days average cycle');
});
