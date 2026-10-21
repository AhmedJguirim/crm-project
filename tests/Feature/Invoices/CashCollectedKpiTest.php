<?php

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Widgets\KpiOverviewWidget;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;
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

    $this->cashInvoice = function (?User $creator, InvoiceStatus $status = InvoiceStatus::Sent, ?Organization $organization = null): Invoice {
        $organization ??= $this->org;

        if ($creator === null) {
            Auth::logout();
        }

        $invoice = Invoice::factory()->create([
            'organization_id' => $organization->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $organization->id])->id,
            'created_by' => $creator?->id,
            'status' => $status,
            'amount' => 10000,
            'amount_paid' => 0,
            'due_at' => '2026-12-01',
        ]);

        $this->actingAs($this->user);

        return $invoice;
    };

    $this->cashEntry = function (Invoice $invoice, string $amount, string $paidOn, bool $refund = false): InvoicePayment {
        $factory = InvoicePayment::factory();

        return ($refund ? $factory->refund() : $factory)->create(['invoice_id' => $invoice->id, 'amount' => $amount, 'paid_on' => $paidOn]);
    };

    $this->cashStats = function (array $pageFilters = []): array {
        $stats = [];

        foreach (invade(Livewire::test(KpiOverviewWidget::class, ['pageFilters' => $pageFilters])->instance())->getStats() as $stat) {
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
});

afterEach(function () {
    Filament::setTenant(null);
});

describe('with the ledger of three invoices', function () {
    beforeEach(function () {
        $invoiceA = ($this->cashInvoice)($this->user);
        ($this->cashEntry)($invoiceA, '500.00', '2026-10-05');
        ($this->cashEntry)($invoiceA, '100.00', '2026-10-12', refund: true);

        $invoiceB = ($this->cashInvoice)($this->bob);
        ($this->cashEntry)($invoiceB, '1000.00', '2026-10-01');
        ($this->cashEntry)($invoiceB, '600.00', '2026-09-30');

        $invoiceC = ($this->cashInvoice)(null);
        ($this->cashEntry)($invoiceC, '200.00', '2026-09-15');
        ($this->cashEntry)($invoiceC, '50.00', '2026-08-31');
    });

    test('cash collected follows the period and the owner', function (array $filters, string $label, string $value, string $description) {
        $stat = ($this->cashStats)($filters)[$label];

        expect($stat['value'])->toBe($value)
            ->and($stat['description'])->toBe($description);
    })->with([
        'everyone this month' => fn () => [[], 'Cash Collected This Month', '$1,400.00', '+75% from $800.00'],
        'me this month' => fn () => [['owner' => 'me'], 'Cash Collected This Month', '$400.00', 'Up from $0.00'],
        'bob this month' => fn () => [['owner' => (string) $this->bob->id], 'Cash Collected This Month', '$1,000.00', '+66.7% from $600.00'],
        'everyone last month' => fn () => [['period' => 'last_month'], 'Cash Collected Last Month', '$800.00', '+1,500% from $50.00'],
        'a custom range' => fn () => [['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-05'], 'Cash Collected In Period', '$1,500.00', '+150% from $600.00'],
    ]);

    test('cash collected sits fifth, between new deals and new contacts', function () {
        expect(array_keys(($this->cashStats)()))->toBe([
            'Revenue Won This Month',
            'Deals Won This Month',
            'Win Rate This Month',
            'New Deals This Month',
            'Cash Collected This Month',
            'New Contacts This Month',
        ]);
    });

    test('a forged owner shows the cash of everyone', function (callable $owner) {
        $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

        expect(($this->cashStats)(['owner' => $owner($carol)])['Cash Collected This Month']['value'])->toBe('$1,400.00');
    })->with([
        'a user of another organization' => [fn (User $carol): string => (string) $carol->id],
        'an injection' => [fn (): string => '1 OR 1=1'],
        'an array' => [fn (): array => ['1']],
    ]);

    test('an owner of another organization sees none of the cash', function () {
        $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $globex = $carol->personalOrganization();
        ($this->cashEntry)(($this->cashInvoice)($carol, InvoiceStatus::Sent, $globex), '9000.00', '2026-10-10');

        $stats = ($this->cashStats)();

        expect($stats['Cash Collected This Month']['value'])->toBe('$1,400.00');

        Livewire::test(KpiOverviewWidget::class)->assertDontSee('9,000');
    });
});

test('a refund-only month is negative and down', function () {
    $invoice = ($this->cashInvoice)($this->user);
    ($this->cashEntry)($invoice, '300.00', '2026-09-10');
    ($this->cashEntry)($invoice, '300.00', '2026-10-03', refund: true);

    $stat = ($this->cashStats)()['Cash Collected This Month'];

    expect($stat['value'])->toBe('-$300.00')
        ->and($stat['description'])->toBe('-200% from $300.00')
        ->and($stat['icon'])->toBe(Heroicon::ArrowTrendingDown)
        ->and($stat['color'])->toBe('danger');
});

test('a negative month after a month of zero reads down from zero', function () {
    $invoice = ($this->cashInvoice)($this->user);
    ($this->cashEntry)($invoice, '50.00', '2026-08-20');
    ($this->cashEntry)($invoice, '50.00', '2026-10-03', refund: true);

    $stat = ($this->cashStats)()['Cash Collected This Month'];

    expect($stat['value'])->toBe('-$50.00')
        ->and($stat['description'])->toBe('Down from $0.00')
        ->and($stat['icon'])->toBe(Heroicon::ArrowTrendingDown)
        ->and($stat['color'])->toBe('danger');
});

test('a positive month after a negative month is up', function () {
    $invoice = ($this->cashInvoice)($this->user);
    ($this->cashEntry)($invoice, '200.00', '2026-08-20');
    ($this->cashEntry)($invoice, '200.00', '2026-09-10', refund: true);
    ($this->cashEntry)($invoice, '100.00', '2026-10-04');

    $stat = ($this->cashStats)()['Cash Collected This Month'];

    expect($stat['value'])->toBe('$100.00')
        ->and($stat['description'])->toBe('+150% from -$200.00')
        ->and($stat['icon'])->toBe(Heroicon::ArrowTrendingUp)
        ->and($stat['color'])->toBe('success');
});

test('the payment of a cancelled invoice still counts', function () {
    ($this->cashEntry)(($this->cashInvoice)($this->user, InvoiceStatus::Cancelled), '70.00', '2026-10-02');

    expect(($this->cashStats)()['Cash Collected This Month']['value'])->toBe('$70.00');
});

test('the days at the edges of the period count and the days outside do not', function () {
    $invoice = ($this->cashInvoice)($this->user);
    ($this->cashEntry)($invoice, '1.00', '2026-10-01');
    ($this->cashEntry)($invoice, '10.00', '2026-10-31');
    ($this->cashEntry)($invoice, '100.00', '2026-09-30');
    ($this->cashEntry)($invoice, '1000.00', '2026-11-01');

    expect(($this->cashStats)()['Cash Collected This Month']['value'])->toBe('$11.00');
});

test('another organization does not leak into the cash', function () {
    ($this->cashEntry)(($this->cashInvoice)($this->user), '1000.00', '2026-10-02');

    $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $globex = $carol->personalOrganization();
    ($this->cashEntry)(($this->cashInvoice)($carol, InvoiceStatus::Sent, $globex), '9000.00', '2026-10-10');

    expect(($this->cashStats)()['Cash Collected This Month']['value'])->toBe('$1,000.00');

    Livewire::test(KpiOverviewWidget::class)
        ->assertSee('$1,000.00')
        ->assertDontSee('9,000');
});

test('cash collected is shown in the currency of the organization', function () {
    $this->org->update(['currency' => Currency::Eur]);
    Filament::setTenant($this->org->refresh());
    ($this->cashEntry)(($this->cashInvoice)($this->user), '8000.00', '2026-10-02');

    Livewire::test(KpiOverviewWidget::class)
        ->assertSeeInOrder(['Cash Collected This Month', '€8,000.00'])
        ->assertDontSee('$8,000.00');
});

test('an organization without payments shows zero dollars', function () {
    $stat = ($this->cashStats)()['Cash Collected This Month'];

    expect($stat['value'])->toBe('$0.00')
        ->and($stat['description'])->toBe('Same as previous period');
});

test('the revenue overview widget is gone', function () {
    expect(file_exists(app_path('Filament/Widgets/RevenueOverviewWidget.php')))->toBeFalse()
        ->and(view()->exists('filament.widgets.revenue-overview-footer'))->toBeFalse();
});

test('a refund month dips below zero on the cash line', function () {
    $invoice = ($this->cashInvoice)($this->user);
    ($this->cashEntry)($invoice, '200.00', '2026-08-20');
    ($this->cashEntry)($invoice, '200.00', '2026-09-10', refund: true);
    ($this->cashEntry)($invoice, '100.00', '2026-10-04');

    $stat = invade(Livewire::test(KpiOverviewWidget::class)->instance())->getStats()[4];

    expect($stat->getLabel())->toBe('Cash Collected This Month')
        ->and($stat->getChart())->toBe([0.0, 0.0, 0.0, 200.0, -200.0, 100.0]);
});

test('the cash line follows the owner and leaves out the ledger of another organization', function () {
    $mine = ($this->cashInvoice)($this->user);
    ($this->cashEntry)($mine, '40.00', '2026-10-04');
    ($this->cashEntry)(($this->cashInvoice)($this->bob), '70.00', '2026-10-05');

    $carol = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    ($this->cashEntry)(($this->cashInvoice)(null, InvoiceStatus::Sent, $carol->personalOrganization()), '9000.00', '2026-09-10');

    $cash = fn (array $filters): array => invade(Livewire::test(KpiOverviewWidget::class, ['pageFilters' => $filters])->instance())->getStats()[4]->getChart();

    expect($cash([]))->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 110.0])
        ->and($cash(['owner' => 'me']))->toBe([0.0, 0.0, 0.0, 0.0, 0.0, 40.0]);
});
