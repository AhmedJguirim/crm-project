<?php

use App\Enums\Currency;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Widgets\MoneyOwedWidget;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Reports\Receivables;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->owedInvoice = fn (InvoiceStatus $status, float|int $amount, float|int $paid, string $dueAt, array $extra = []): Invoice => Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'status' => $status,
        'amount' => $amount,
        'amount_paid' => $paid,
        'paid_at' => null,
        'due_at' => $dueAt,
        ...$extra,
    ]);

    $this->edgeInvoices = function (): void {
        foreach ([
            [InvoiceStatus::Sent, 1000, 0, '2026-02-21'],
            [InvoiceStatus::Partial, 1000, 400, '2026-03-01'],
            [InvoiceStatus::Sent, 200, 0, '2026-02-20'],
            [InvoiceStatus::Sent, 300, 0, '2026-01-22'],
            [InvoiceStatus::Overdue, 20, 0, '2026-02-25'],
            [InvoiceStatus::Sent, 40, 0, '2026-01-21'],
            [InvoiceStatus::Partial, 100, 40, '2025-12-23'],
            [InvoiceStatus::Sent, 7, 0, '2025-12-22'],
            [InvoiceStatus::Overdue, 3, 0, '2025-11-23'],
            [InvoiceStatus::Sent, 5, 0, '2025-11-22'],
            [InvoiceStatus::Draft, 9000, 0, '2025-01-01'],
            [InvoiceStatus::Cancelled, 9000, 0, '2025-01-01'],
            [InvoiceStatus::Paid, 9000, 9000, '2025-01-01'],
        ] as [$status, $amount, $paid, $dueAt]) {
            ($this->owedInvoice)($status, $amount, $paid, $dueAt);
        }

        $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();

        Invoice::factory()->create([
            'organization_id' => $otherOrg->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $otherOrg->id])->id,
            'status' => InvoiceStatus::Sent,
            'amount' => 9000,
            'amount_paid' => 0,
            'due_at' => '2025-01-01',
        ]);
    };
});

afterEach(function () {
    Carbon::setTestNow();
});

test('the buckets split at their exact edges and ignore other statuses and organizations', function () {
    ($this->edgeInvoices)();

    $aging = Receivables::aging('2026-02-21');

    expect(array_keys($aging))->toBe(['not_due', 'days_1_30', 'days_31_60', 'days_61_90', 'over_90'])
        ->and($aging)->toBe([
            'not_due' => ['count' => 2, 'balance' => 1600.0],
            'days_1_30' => ['count' => 3, 'balance' => 520.0],
            'days_31_60' => ['count' => 2, 'balance' => 100.0],
            'days_61_90' => ['count' => 2, 'balance' => 10.0],
            'over_90' => ['count' => 1, 'balance' => 5.0],
        ]);
});

test('the widget shows the total and every bucket', function () {
    ($this->edgeInvoices)();

    Livewire::test(MoneyOwedWidget::class)
        ->assertSeeInOrder(['Money owed', 'Unpaid invoices of the whole organization as of today, by how late they are.'])
        ->assertSeeInOrder(['Outstanding', '$2,235.00', '10 unpaid invoices · $635.00 overdue'])
        ->assertSeeInOrder(['Not due yet', '$1,600.00', '2 invoices'])
        ->assertSeeInOrder(['1–30 days late', '$520.00', '3 invoices'])
        ->assertSeeInOrder(['31–60 days late', '$100.00', '2 invoices'])
        ->assertSeeInOrder(['61–90 days late', '$10.00', '2 invoices'])
        ->assertSeeInOrder(['Over 90 days late', '$5.00', '1 invoice'])
        ->assertDontSee('$9,000.00')
        ->assertDontSee('$11,235.00');
});

test('without an unpaid invoice everything is zero', function () {
    ($this->owedInvoice)(InvoiceStatus::Paid, 500, 500, '2026-02-01', ['paid_at' => now()]);

    $expected = ['count' => 0, 'balance' => 0.0];

    expect(Receivables::aging('2026-02-21'))->toBe([
        'not_due' => $expected,
        'days_1_30' => $expected,
        'days_31_60' => $expected,
        'days_61_90' => $expected,
        'over_90' => $expected,
    ]);

    Livewire::test(MoneyOwedWidget::class)
        ->assertSeeInOrder(['Outstanding', '$0.00', 'No unpaid invoices'])
        ->assertSeeInOrder(['Not due yet', '$0.00', '0 invoices'])
        ->assertSeeInOrder(['Over 90 days late', '$0.00', '0 invoices']);
});

test('the amounts use the currency of the organization', function () {
    $this->org->update(['currency' => Currency::Eur]);
    ($this->owedInvoice)(InvoiceStatus::Sent, 1200, 0, '2026-02-26');

    Livewire::test(MoneyOwedWidget::class)
        ->assertSee('€1,200.00')
        ->assertDontSee('$1,200.00');
});

test('today follows the organization timezone', function (string $timezone, string $bucket) {
    $this->org->update(['timezone' => $timezone]);
    Filament::setTenant($this->org->refresh());
    ($this->owedInvoice)(InvoiceStatus::Sent, 700, 0, '2026-02-21');

    Livewire::test(MoneyOwedWidget::class)
        ->assertSeeInOrder([$bucket, '$700.00', '1 invoice']);
})->with([
    'UTC' => ['UTC', 'Not due yet'],
    'Auckland' => ['Pacific/Auckland', '1–30 days late'],
]);

test('the stats link to the invoice list only when there is something to list', function () {
    ($this->owedInvoice)(InvoiceStatus::Sent, 100, 0, '2026-02-10');

    $widget = Livewire::test(MoneyOwedWidget::class)->instance();
    $getStats = new ReflectionMethod($widget, 'getStats');

    $stats = collect($getStats->invoke($widget))->keyBy(fn ($stat) => (string) $stat->getLabel());

    $list = InvoiceResource::getUrl('index');

    expect($stats['1–30 days late']->getUrl())->toBe($list.'?tableFilters[overdue][isActive]=1')
        ->and($stats['31–60 days late']->getUrl())->toBeNull()
        ->and($stats['Over 90 days late']->getUrl())->toBeNull()
        ->and($stats['Not due yet']->getUrl())->toBeNull()
        ->and($stats['Outstanding']->getUrl())->toBe($list.'?tableFilters[status][values][0]=sent&tableFilters[status][values][1]=partial&tableFilters[status][values][2]=overdue');
});

test('the dashboard period and owner filters do not change the figures', function () {
    $this->org->members()->attach(User::factory()->onboardingCompleted()->create(), ['role' => OrganizationRole::Member->value]);
    ($this->owedInvoice)(InvoiceStatus::Sent, 100, 0, '2026-02-10');

    Livewire::test(MoneyOwedWidget::class, ['pageFilters' => ['owner' => 'me', 'period' => 'last_month']])
        ->assertSeeInOrder(['Outstanding', '$100.00']);
});

test('a partly paid invoice counts for its balance', function () {
    foreach ([
        [InvoiceStatus::Sent, 1200, 0, '2026-02-26'],
        [InvoiceStatus::Partial, 5600, 2000, '2026-02-26'],
        [InvoiceStatus::Overdue, 800, 0, '2026-02-18'],
    ] as [$status, $amount, $paid, $dueAt]) {
        ($this->owedInvoice)($status, $amount, $paid, $dueAt);
    }

    Livewire::test(MoneyOwedWidget::class)
        ->assertSeeInOrder(['Outstanding', '$5,600.00'])
        ->assertSeeInOrder(['1–30 days late', '$800.00', '1 invoice']);
});

test('a past due partial invoice adds only its balance to the overdue value', function () {
    ($this->owedInvoice)(InvoiceStatus::Partial, 5600, 2000, '2026-02-19');
    ($this->owedInvoice)(InvoiceStatus::Overdue, 9200, 0, '2026-02-13');

    Livewire::test(MoneyOwedWidget::class)
        ->assertSeeInOrder(['Outstanding', '$12,800.00', '2 unpaid invoices · $12,800.00 overdue']);
});

test('another organization balances are not counted in the outstanding total', function () {
    ($this->owedInvoice)(InvoiceStatus::Partial, 1000, 400, '2026-02-26');

    $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();

    Invoice::factory()->create([
        'organization_id' => $otherOrg->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $otherOrg->id])->id,
        'status' => InvoiceStatus::Sent,
        'amount' => 9000,
        'amount_paid' => 0,
        'due_at' => '2026-02-26',
    ]);

    Livewire::test(MoneyOwedWidget::class)
        ->assertSeeInOrder(['Outstanding', '$600.00'])
        ->assertDontSee('$9,600.00')
        ->assertDontSee('$9,000.00');
});

test('an invoice of a soft deleted contact still counts', function () {
    $invoice = ($this->owedInvoice)(InvoiceStatus::Overdue, 300, 0, '2026-02-13');
    $invoice->contact->delete();

    Livewire::test(MoneyOwedWidget::class)
        ->assertSeeInOrder(['1–30 days late', '$300.00']);
});

test('every role sees the money owed', function (OrganizationRole $role) {
    $user = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($user, ['role' => $role->value]);
    ($this->owedInvoice)(InvoiceStatus::Overdue, 400, 0, '2026-02-13');
    $this->actingAs($user);
    Filament::setTenant($this->org);

    Livewire::test(MoneyOwedWidget::class)
        ->assertSuccessful()
        ->assertSee('$400.00');
})->with([
    'owner' => OrganizationRole::Owner,
    'admin' => OrganizationRole::Admin,
    'member' => OrganizationRole::Member,
    'viewer' => OrganizationRole::Viewer,
]);

test('the dashboard renders the widget', function () {
    $this->get(Filament::getUrl($this->org))
        ->assertOk()
        ->assertSeeLivewire(MoneyOwedWidget::class);
});
