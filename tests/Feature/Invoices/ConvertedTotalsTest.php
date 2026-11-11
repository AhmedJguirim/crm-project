<?php

use App\Enums\Currency;
use App\Enums\InvoicePaymentKind;
use App\Enums\InvoiceStatus;
use App\Enums\OrganizationRole;
use App\Enums\PaymentMethod;
use App\Enums\ReportPeriodPreset;
use App\Filament\Resources\Deals\Widgets\DealInvoicesWidget;
use App\Filament\Widgets\MoneyOwedWidget;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Invoices\InvoiceLedger;
use App\Services\Reports\DashboardKpis;
use App\Services\Reports\Receivables;
use App\Services\Reports\RevenueByClient;
use App\Support\Reports\HomeAmount;
use App\Support\Reports\ReportPeriod;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-11-11 10:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->org->update(['currency' => Currency::Tnd, 'timezone' => 'UTC']);
    $this->actingAs($this->user);
    Filament::setTenant($this->org->refresh());
    $this->nbsp = "\u{00A0}";

    $this->contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Sami Ben Ali']);

    $this->makeInvoice = fn (string $number, Currency $currency, ?string $rate, InvoiceStatus $status, string $amount, array $extra = []): Invoice => Invoice::factory()
        ->inCurrency($currency, $rate)
        ->create([
            'organization_id' => $this->org->id,
            'contact_id' => $this->contact->id,
            'created_by' => $this->user->id,
            'invoice_number' => $number,
            'status' => $status,
            'amount' => $amount,
            'amount_paid' => 0,
            'paid_at' => null,
            'issued_at' => '2026-11-01',
            'due_at' => '2026-11-30',
            ...$extra,
        ]);

    $this->ledger = fn (Invoice $invoice, InvoicePaymentKind $kind, string $amount, string $paidOn) => app(InvoiceLedger::class)
        ->record($invoice, $kind, $amount, $paidOn, PaymentMethod::BankTransfer, null, $this->user);

    $this->eur = ($this->makeInvoice)('INV-EUR', Currency::Eur, '3.4', InvoiceStatus::Sent, '1000.00');
    ($this->ledger)($this->eur, InvoicePaymentKind::Payment, '500.00', '2026-11-05');
    $this->tnd = ($this->makeInvoice)('INV-TND', Currency::Tnd, null, InvoiceStatus::Sent, '250.00');
    $this->usd = ($this->makeInvoice)('INV-USD', Currency::Usd, '3.1', InvoiceStatus::Sent, '200.00', ['due_at' => '2026-10-01']);

    $this->thisMonth = ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth);
    $this->cash = fn (?int $ownerId = null): array => DashboardKpis::cashCollectedByPeriod([$this->thisMonth], $ownerId);
});

test('money owed converts each balance', function () {
    expect($this->eur->refresh()->status)->toBe(InvoiceStatus::Partial);

    expect(Receivables::aging('2026-11-11'))->toBe([
        'not_due' => ['count' => 2, 'balance' => 1950.0],
        'days_1_30' => ['count' => 0, 'balance' => 0.0],
        'days_31_60' => ['count' => 1, 'balance' => 620.0],
        'days_61_90' => ['count' => 0, 'balance' => 0.0],
        'over_90' => ['count' => 0, 'balance' => 0.0],
    ]);

    Livewire::test(MoneyOwedWidget::class)
        ->assertSee('TND')
        ->assertSee('2,570');
});

test('cash collected converts each payment and refund at its invoice rate', function () {
    ($this->ledger)($this->eur, InvoicePaymentKind::Refund, '100.00', '2026-11-06');

    expect(($this->cash)())->toBe([1360.0]);
});

test('rounding is per ledger row', function () {
    $invoice = ($this->makeInvoice)('INV-R', Currency::Eur, '3.335', InvoiceStatus::Sent, '500.00');
    ($this->ledger)($invoice, InvoicePaymentKind::Payment, '100.01', '2026-11-07');
    ($this->ledger)($invoice, InvoicePaymentKind::Payment, '100.01', '2026-11-07');

    expect(($this->cash)())->toBe([2367.06]);
});

test('a balance is rounded per invoice before it is summed', function () {
    ($this->makeInvoice)('INV-R1', Currency::Eur, '3.335', InvoiceStatus::Sent, '100.01');
    ($this->makeInvoice)('INV-R2', Currency::Eur, '3.335', InvoiceStatus::Sent, '100.01');

    expect(Receivables::aging('2026-11-11')['not_due']['balance'])->toBe(2617.06);
});

test('a later rate on another invoice never moves a frozen one', function () {
    $other = ($this->makeInvoice)('INV-EUR2', Currency::Eur, '3.5', InvoiceStatus::Sent, '100.00');
    ($this->ledger)($other, InvoicePaymentKind::Payment, '100.00', '2026-11-08');

    expect($other->refresh()->status)->toBe(InvoiceStatus::Paid)
        ->and(($this->cash)())->toBe([2050.0]);
});

test('the owner filter still uses the invoice creator after the join', function () {
    $bob = User::factory()->onboardingCompleted()->create();
    $this->org->members()->attach($bob, ['role' => OrganizationRole::Member->value]);

    $invoice = ($this->makeInvoice)('INV-B', Currency::Eur, '3.4', InvoiceStatus::Sent, '100.00', ['created_by' => $bob->id]);
    ($this->ledger)($invoice, InvoicePaymentKind::Payment, '100.00', '2026-11-05');

    expect(($this->cash)($this->user->id))->toBe([1700.0])
        ->and(($this->cash)($bob->id))->toBe([340.0])
        ->and(($this->cash)())->toBe([2040.0]);
});

test('revenue by client converts collected and outstanding', function () {
    $rows = RevenueByClient::forPeriod($this->thisMonth, null);

    expect($rows['contact-'.$this->contact->id])->toMatchArray(['client' => 'Sami Ben Ali', 'collected' => 1700.0, 'outstanding' => 2570.0])
        ->and($rows['total'])->toMatchArray(['collected' => 1700.0, 'outstanding' => 2570.0]);
});

test('revenue by client counts a refund negative at its invoice rate', function () {
    ($this->ledger)($this->eur, InvoicePaymentKind::Refund, '100.00', '2026-11-06');

    $rows = RevenueByClient::forPeriod($this->thisMonth, null);

    expect($rows['total'])->toMatchArray(['collected' => 1360.0, 'outstanding' => 2910.0]);
});

test('a foreign row without a rate is left out of cash and revenue', function () {
    $stranger = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Zed Unrated']);
    $cancelled = ($this->makeInvoice)('INV-NORATE', Currency::Eur, null, InvoiceStatus::Cancelled, '300.00', ['contact_id' => $stranger->id]);
    InvoicePayment::factory()->create(['invoice_id' => $cancelled->id, 'amount' => '300.00', 'paid_on' => '2026-11-05']);

    expect(($this->cash)())->toBe([1700.0]);

    $rows = RevenueByClient::forPeriod($this->thisMonth, null);

    expect($rows)->not->toHaveKey('contact-'.$stranger->id)
        ->and($rows['total']['collected'])->toBe(1700.0);
});

test('another organization invoices never count', function () {
    $aging = Receivables::aging('2026-11-11');
    $cash = ($this->cash)();
    $revenue = RevenueByClient::forPeriod($this->thisMonth, null);

    $globex = Organization::factory()->create(['name' => 'Globex Demo', 'currency' => Currency::Eur]);
    $invoice = Invoice::factory()->inCurrency(Currency::Eur, '1')->create([
        'organization_id' => $globex->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $globex->id])->id,
        'status' => InvoiceStatus::Sent,
        'amount' => '999.00',
        'amount_paid' => 0,
        'paid_at' => null,
        'due_at' => '2026-11-30',
    ]);
    InvoicePayment::factory()->create(['invoice_id' => $invoice->id, 'amount' => '999.00', 'paid_on' => '2026-11-05']);

    expect(Invoice::query()->withoutGlobalScope('organization')->where('organization_id', $globex->id)->count())->toBe(1)
        ->and(Receivables::aging('2026-11-11'))->toBe($aging)
        ->and(($this->cash)())->toBe($cash)
        ->and(RevenueByClient::forPeriod($this->thisMonth, null))->toBe($revenue);
});

describe('the deal invoices widget footer', function () {
    beforeEach(function () {
        $this->deal = Deal::factory()->create([
            'organization_id' => $this->org->id,
            'contact_id' => $this->contact->id,
            'created_by' => $this->user->id,
            'title' => 'Site refonte',
        ]);
        $this->eur->update(['deal_id' => $this->deal->id]);
        $this->tnd->update(['deal_id' => $this->deal->id]);
    });

    test('is in the home currency', function () {
        Livewire::test(DealInvoicesWidget::class, ['record' => $this->deal])
            ->assertSee('€1,000.00')
            ->assertSee("TND{$this->nbsp}250.00")
            ->assertSee("TND{$this->nbsp}3,650.00")
            ->assertSee("TND{$this->nbsp}1,700.00")
            ->assertSee("TND{$this->nbsp}1,950.00")
            ->assertDontSee("TND{$this->nbsp}3,650.000")
            ->assertDontSee("TND{$this->nbsp}1,700.000")
            ->assertDontSee("TND{$this->nbsp}1,950.000")
            ->assertDontSee('1,250.00')
            ->assertDontSee('no exchange rate yet');
    });

    test('leaves out foreign invoices without a rate and names them', function (int $drafts, string $note) {
        foreach (range(1, $drafts) as $number) {
            ($this->makeInvoice)("INV-D{$number}", Currency::Eur, null, InvoiceStatus::Draft, '300.00', ['deal_id' => $this->deal->id]);
        }
        ($this->makeInvoice)('INV-DT', Currency::Tnd, null, InvoiceStatus::Draft, '100.00', ['deal_id' => $this->deal->id]);

        Livewire::test(DealInvoicesWidget::class, ['record' => $this->deal])
            ->assertSee("TND{$this->nbsp}3,750.00")
            ->assertSee($note);
    })->with([
        'one' => [1, '1 invoice in another currency has no exchange rate yet and is not in the totals.'],
        'two' => [2, '2 invoices in another currency have no exchange rate yet and are not in the totals.'],
    ]);

    test('does not count the invoices of another deal', function () {
        $other = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->contact->id, 'created_by' => $this->user->id]);
        ($this->makeInvoice)('INV-OTHER', Currency::Tnd, null, InvoiceStatus::Sent, '5000.00', ['deal_id' => $other->id]);

        Livewire::test(DealInvoicesWidget::class, ['record' => $this->deal])
            ->assertSee("TND{$this->nbsp}3,650.00")
            ->assertDontSee('8,650.00');
    });
});

test('the converted amount only takes known expressions', function (string $expression) {
    HomeAmount::sql($expression);
})->throws(InvalidArgumentException::class, 'Unknown amount expression for a home-currency conversion.')->with([
    'injection' => 'amount); DROP TABLE invoices; --',
    'arithmetic' => 'invoices.amount * 0',
    'other table' => 'deals.value',
]);

test('the SQL helper', function () {
    expect(HomeAmount::rateSql())->toBe('COALESCE(invoices.exchange_rate, CASE WHEN invoices.currency = ? THEN 1 END)')
        ->and(HomeAmount::sql('invoices.amount'))->toBe('ROUND((invoices.amount) * COALESCE(invoices.exchange_rate, CASE WHEN invoices.currency = ? THEN 1 END), 2)')
        ->and(HomeAmount::binding())->toBe('TND');
});

test('the home currency binding defaults to USD without a tenant', function () {
    Filament::setTenant(null);
    auth()->logout();

    expect(HomeAmount::binding())->toBe('USD');
});
