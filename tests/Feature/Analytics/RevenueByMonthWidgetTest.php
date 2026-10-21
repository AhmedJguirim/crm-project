<?php

use App\Enums\DealStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Widgets\RevenueByMonthWidget;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Livewire\invade;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Alice Archer']);
    $this->org = $this->user->personalOrganization();
    $this->org->update(['timezone' => 'Europe/Paris']);
    $this->actingAs($this->user);
    Filament::setTenant($this->org->refresh());

    $this->bob = User::factory()->onboardingCompleted()->create(['name' => 'Bob Brown']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);

    $this->chartDeal = fn (DealStatus $status, string $value, User $owner, string $at, ?Organization $organization = null): Deal => Deal::factory()
        ->inStage($status === DealStatus::Won ? 'Won' : 'Lead')
        ->create([
            'organization_id' => ($organization ?? $this->org)->id,
            'created_by' => $this->user->id,
            'owner_id' => $owner->id,
            'status' => $status,
            'value' => $value,
            'won_at' => $status === DealStatus::Won ? $at : null,
            'created_at' => $status === DealStatus::Won ? '2026-01-01 10:00:00' : $at,
        ]);

    $this->chartEntry = function (User $creator, string $amount, string $paidOn, bool $refund = false, ?Organization $organization = null): InvoicePayment {
        $organization ??= $this->org;

        $invoice = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $organization->id])->id,
            'created_by' => $creator->id,
            'status' => InvoiceStatus::Sent,
            'amount' => 10000,
            'amount_paid' => 0,
            'due_at' => '2026-12-01',
        ]);
        $factory = InvoicePayment::factory();

        return ($refund ? $factory->refund() : $factory)->create(['invoice_id' => $invoice->id, 'amount' => $amount, 'paid_on' => $paidOn]);
    };

    $this->chartData = fn (array $pageFilters = []): array => invade(Livewire::test(RevenueByMonthWidget::class, ['pageFilters' => $pageFilters])->instance())->getData();

    $this->chartSeries = fn (array $pageFilters = []): array => [
        'cash' => ($this->chartData)($pageFilters)['datasets'][0]['data'],
        'won' => ($this->chartData)($pageFilters)['datasets'][1]['data'],
    ];

    ($this->chartDeal)(DealStatus::Won, '1000', $this->user, '2026-09-30 22:30:00');
    ($this->chartDeal)(DealStatus::Won, '500', $this->bob, '2026-09-30 21:30:00');
    ($this->chartDeal)(DealStatus::Won, '300', $this->bob, '2026-08-15 10:00:00');
    ($this->chartDeal)(DealStatus::Won, '200', $this->user, '2025-10-31 23:30:00');
    ($this->chartDeal)(DealStatus::Won, '7000', $this->user, '2025-10-31 22:30:00');
    ($this->chartDeal)(DealStatus::Open, '4000', $this->user, '2026-10-05 10:00:00');

    ($this->chartEntry)($this->user, '400.00', '2026-10-01');
    ($this->chartEntry)($this->user, '150.00', '2026-10-05', refund: true);
    ($this->chartEntry)($this->bob, '600.00', '2026-09-30');
    ($this->chartEntry)($this->user, '80.00', '2026-07-10', refund: true);
    ($this->chartEntry)($this->user, '50.00', '2025-10-31');

    $this->everyoneCash = [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, -80.0, 0.0, 600.0, 250.0];
    $this->everyoneWon = [200.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 300.0, 500.0, 1000.0];
});

afterEach(function () {
    Filament::setTenant(null);
});

test('everyone over the last 12 local months', function () {
    expect(($this->chartData)()['labels'])->toBe(['Nov 2025', 'Dec 2025', 'Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026', 'May 2026', 'Jun 2026', 'Jul 2026', 'Aug 2026', 'Sep 2026', 'Oct 2026'])
        ->and(($this->chartSeries)())->toBe(['cash' => $this->everyoneCash, 'won' => $this->everyoneWon]);
});

test('the owner filter narrows both series', function (callable $owner, array $cash, array $won) {
    expect(($this->chartSeries)(['owner' => $owner($this)]))->toBe(['cash' => $cash, 'won' => $won]);
})->with([
    'me' => [
        fn (): string => 'me',
        [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, -80.0, 0.0, 0.0, 250.0],
        [200.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 1000.0],
    ],
    'Bob' => [
        fn ($test): string => (string) $test->bob->id,
        [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 600.0, 0.0],
        [0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 300.0, 500.0, 0.0],
    ],
]);

test('the period filter does not move the chart', function () {
    expect(($this->chartData)(['period' => 'last_month'])['labels'][11])->toBe('Oct 2026')
        ->and(($this->chartSeries)(['period' => 'last_month']))->toBe(['cash' => $this->everyoneCash, 'won' => $this->everyoneWon]);
});

test('a forged owner shows everyone', function (callable $owner) {
    $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    expect(($this->chartSeries)(['owner' => $owner($carol)]))->toBe(['cash' => $this->everyoneCash, 'won' => $this->everyoneWon]);
})->with([
    'a user of another organization' => [fn (User $carol): string => (string) $carol->id],
    'an injection' => [fn (): string => '1 OR 1=1'],
    'an array' => [fn (): array => ['1']],
]);

test('another organization does not leak', function () {
    $globex = Organization::factory()->create();
    $other = User::factory()->onboardingCompleted()->create();
    ($this->chartDeal)(DealStatus::Won, '9000', $other, '2026-10-10 10:00:00', $globex);
    ($this->chartEntry)($other, '9000.00', '2026-10-10', organization: $globex);

    expect(($this->chartSeries)())->toBe(['cash' => $this->everyoneCash, 'won' => $this->everyoneWon]);
});

test('it reads deals once and the ledger once', function () {
    $widget = Livewire::test(RevenueByMonthWidget::class)->instance();

    DB::enableQueryLog();
    invade($widget)->getData();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    expect(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "deals"')))->toHaveCount(1)
        ->and(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'from "invoice_payments"')))->toHaveCount(1);
});

test('it is a bar chart with titled, coloured datasets', function () {
    $widget = Livewire::test(RevenueByMonthWidget::class)->instance();
    $data = ($this->chartData)();

    expect(invade($widget)->getType())->toBe('bar')
        ->and($widget->getHeading())->toBe('Revenue by month')
        ->and($widget->getDescription())->toBe('Cash collected (payments minus refunds) and value of deals won, per month over the last 12 months.')
        ->and($data['datasets'][0])->toMatchArray(['label' => 'Cash collected', 'backgroundColor' => '#3b82f6', 'borderColor' => '#3b82f6'])
        ->and($data['datasets'][1])->toMatchArray(['label' => 'Won deal value', 'backgroundColor' => '#ea580c', 'borderColor' => '#ea580c'])
        ->and(invade($widget)->getOptions())->toBe(['scales' => ['y' => ['beginAtZero' => true]]])
        ->and(invade($widget)->getPollingInterval())->toBeNull()
        ->and($widget->getColumnSpan())->toBe('full')
        ->and(RevenueByMonthWidget::getSort())->toBe(-19);
});

test('every role sees the chart', function (OrganizationRole $role) {
    $member = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($member, ['role' => $role->value]);
    $this->actingAs($member);

    expect(RevenueByMonthWidget::canView())->toBeTrue();

    Livewire::test(RevenueByMonthWidget::class)->assertSee('Revenue by month');
})->with(OrganizationRole::cases());
