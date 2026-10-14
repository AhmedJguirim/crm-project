<?php

use App\Enums\DealStage;
use App\Enums\InvoiceStatus;
use App\Enums\TaskStatus;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Widgets\ContactsOverviewWidget;
use App\Filament\Widgets\DashboardTasksWidget;
use App\Filament\Widgets\DealsOverviewWidget;
use App\Filament\Widgets\RevenueOverviewWidget;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use App\Services\Deals\DealStageMover;
use App\Support\LocalTime;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Livewire;

use function Livewire\invade;

beforeEach(function () {
    $this->alice = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->alice->personalOrganization();
    $this->actingAs($this->alice);

    $this->inZone = function (string $timezone, string $utcNow): void {
        $this->org->update(['timezone' => $timezone]);
        Filament::setTenant($this->org->refresh());
        $this->travelTo(CarbonImmutable::parse($utcNow, 'UTC'));
    };

    $this->makeTask = fn (string $title, string $dueAt, TaskStatus $status = TaskStatus::Pending): Task => Task::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'owner_id' => $this->alice->id,
        'title' => $title,
        'status' => $status,
        'due_at' => $dueAt,
    ]);

    $this->makeDeal = fn (string $title, ?string $closeDate, DealStage $stage = DealStage::Lead): Deal => Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->alice->id,
        'title' => $title,
        'expected_close_date' => $closeDate,
        ...DealStageMover::attributesFor($stage),
    ]);

    $this->makeInvoice = fn (InvoiceStatus $status, string $dueAt, array $attributes = []): Invoice => Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id, 'created_at' => '2026-01-01 00:00:00'])->id,
        'status' => $status,
        'due_at' => $dueAt,
        'amount_paid' => 0,
        'paid_at' => null,
        ...$attributes,
    ]);

    $this->stats = fn (string $widget): array => collect(invade(Livewire::test($widget)->instance())->getStats())
        ->mapWithKeys(fn (Stat $stat): array => [(string) $stat->getLabel() => (string) $stat->getValue()])
        ->all();
});

afterEach(function () {
    Filament::setTenant(null);
});

test('the Today task filter uses the local day', function () {
    ($this->inZone)('Asia/Tokyo', '2026-10-12 23:30:00');
    ($this->makeTask)('Local midnight', '2026-10-12 15:00:00');
    ($this->makeTask)('Local morning', '2026-10-13 01:00:00');
    ($this->makeTask)('Local yesterday', '2026-10-12 14:00:00');
    ($this->makeTask)('Local tomorrow', '2026-10-13 15:00:00');
    ($this->makeTask)('Done today', '2026-10-13 01:00:00', TaskStatus::Done);

    $page = Livewire::test(ListTasks::class)->filterTable('today');

    $page->assertCanSeeTableRecords(Task::query()->whereIn('title', ['Local midnight', 'Local morning'])->get())
        ->assertCountTableRecords(2);
});

test('due this week uses the local Monday to Sunday', function () {
    ($this->inZone)('America/New_York', '2026-10-12 02:00:00');
    ($this->makeTask)('Saturday', '2026-10-10 15:00:00');
    ($this->makeTask)('Sunday late', '2026-10-12 03:30:00');
    ($this->makeTask)('Previous Sunday', '2026-10-05 03:30:00');
    ($this->makeTask)('UTC Tuesday', '2026-10-13 15:00:00');

    expect(Task::query()->dueThisWeek()->orderBy('title')->pluck('title')->all())->toBe(['Saturday', 'Sunday late']);

    Livewire::test(ListTasks::class)
        ->filterTable('this_week')
        ->assertCountTableRecords(2);

    expect(($this->stats)(DashboardTasksWidget::class)['Due this week'])->toBe('2');
});

test('closing this week uses local dates', function () {
    ($this->inZone)('America/New_York', '2026-10-12 02:00:00');
    ($this->makeDeal)('Friday close', '2026-10-09');
    ($this->makeDeal)('Sunday close', '2026-10-11');
    ($this->makeDeal)('Previous Sunday close', '2026-10-04');
    ($this->makeDeal)('Next Wednesday close', '2026-10-14');

    expect(Deal::query()->closingThisWeek()->orderBy('title')->pluck('title')->all())->toBe(['Friday close', 'Sunday close']);

    Livewire::test(ListDeals::class)
        ->filterTable('closing_this_week')
        ->assertCountTableRecords(2);
});

test('a deal is overdue from the local day after its close date', function (DealStage $stage, string $date, bool $overdue) {
    ($this->inZone)('Asia/Tokyo', '2026-10-12 23:30:00');

    expect(($this->makeDeal)('Deal', $date, $stage)->isOverdue())->toBe($overdue);
})->with([
    'open, closing on the UTC day' => [DealStage::Lead, '2026-10-12', true],
    'open, closing on the local day' => [DealStage::Lead, '2026-10-13', false],
    'won' => [DealStage::Won, '2026-10-12', false],
]);

test('an invoice is overdue from the local day after its due date', function (InvoiceStatus $status, string $due, bool $included, bool $pastDue) {
    ($this->inZone)('Asia/Tokyo', '2026-10-12 23:30:00');
    $invoice = ($this->makeInvoice)($status, $due);

    expect(Invoice::query()->overdue()->whereKey($invoice->id)->exists())->toBe($included)
        ->and($invoice->refresh()->isPastDue())->toBe($pastDue);
})->with([
    'sent, due on the UTC day' => [InvoiceStatus::Sent, '2026-10-12', true, true],
    'sent, due on the local day' => [InvoiceStatus::Sent, '2026-10-13', false, false],
    'partial, due long ago' => [InvoiceStatus::Partial, '2026-10-01', true, true],
    'paid' => [InvoiceStatus::Paid, '2026-10-01', false, false],
]);

test('invoice dates default to the local day and mark as paid stamps it', function () {
    ($this->inZone)('Asia/Tokyo', '2026-10-12 23:30:00');

    Livewire::test(CreateInvoice::class)
        ->assertSet('data.issued_at', '2026-10-13')
        ->assertSet('data.due_at', '2026-11-12');

    $invoice = ($this->makeInvoice)(InvoiceStatus::Sent, '2026-10-20');

    Livewire::test(ListInvoices::class)->callAction(TestAction::make('markAsPaid')->table($invoice));

    expect($invoice->refresh()->paid_at->toDateString())->toBe('2026-10-13');
});

test('the invoice view and edit pages stamp the paid date with the local day', function () {
    ($this->inZone)('Asia/Tokyo', '2026-10-12 23:30:00');

    $viewed = ($this->makeInvoice)(InvoiceStatus::Sent, '2026-10-20');
    Livewire::test(ViewInvoice::class, ['record' => $viewed->id])->callAction('markAsPaid');

    $edited = ($this->makeInvoice)(InvoiceStatus::Sent, '2026-10-20');
    Livewire::test(EditInvoice::class, ['record' => $edited->id])
        ->fillForm(['status' => InvoiceStatus::Paid, 'paid_at' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($viewed->refresh()->paid_at->toDateString())->toBe('2026-10-13')
        ->and($edited->refresh()->paid_at->toDateString())->toBe('2026-10-13');
});

test('the month widgets count the local month', function () {
    ($this->inZone)('Europe/Paris', '2026-10-31 23:30:00');
    Contact::factory()->create(['organization_id' => $this->org->id, 'created_at' => '2026-10-31 23:10:00']);
    Contact::factory()->create(['organization_id' => $this->org->id, 'created_at' => '2026-10-31 22:50:00']);
    Deal::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->alice->id, 'value' => 300, ...DealStageMover::attributesFor(DealStage::Won), 'won_at' => '2026-10-31 23:10:00']);
    Deal::factory()->create(['organization_id' => $this->org->id, 'created_by' => $this->alice->id, 'value' => 200, ...DealStageMover::attributesFor(DealStage::Won), 'won_at' => '2026-10-31 22:50:00']);
    ($this->makeInvoice)(InvoiceStatus::Paid, '2026-11-30', ['amount' => 100, 'amount_paid' => 100, 'paid_at' => '2026-11-01']);
    ($this->makeInvoice)(InvoiceStatus::Paid, '2026-11-30', ['amount' => 40, 'amount_paid' => 40, 'paid_at' => '2026-10-31']);

    expect(($this->stats)(ContactsOverviewWidget::class)['New This Month'])->toBe('1')
        ->and(($this->stats)(DealsOverviewWidget::class)['Won This Month'])->toBe('1')
        ->and(($this->stats)(RevenueOverviewWidget::class)['Paid This Month'])->toBe('$100.00');
});

test('YTD revenue counts the local year', function () {
    ($this->inZone)('Europe/Paris', '2026-12-31 23:30:00');
    ($this->makeInvoice)(InvoiceStatus::Paid, '2027-01-30', ['amount' => 70, 'amount_paid' => 70, 'paid_at' => '2027-01-01']);
    ($this->makeInvoice)(InvoiceStatus::Paid, '2027-01-30', ['amount' => 30, 'amount_paid' => 30, 'paid_at' => '2026-12-31']);

    expect(($this->stats)(RevenueOverviewWidget::class)['YTD Revenue'])->toBe('$70.00');
});

test('an organization in UTC keeps the server day', function () {
    ($this->inZone)('UTC', '2026-10-12 23:30:00');
    ($this->makeTask)('Today UTC', '2026-10-12 23:45:00');
    ($this->makeTask)('Tomorrow UTC', '2026-10-13 00:30:00');

    Livewire::test(ListTasks::class)
        ->filterTable('today')
        ->assertCountTableRecords(1)
        ->assertSee('Today UTC')
        ->assertDontSee('Tomorrow UTC');
});

test('the periods of one organization do not leak to another', function () {
    ($this->inZone)('Asia/Tokyo', '2026-10-12 23:30:00');
    $globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    Task::factory()->create([
        'organization_id' => $globex->personalOrganization()->id,
        'created_by' => $globex->id,
        'owner_id' => $globex->id,
        'status' => TaskStatus::Pending,
        'due_at' => '2026-10-13 01:00:00',
    ]);

    Livewire::test(ListTasks::class)->filterTable('today')->assertCountTableRecords(0);
    expect(($this->stats)(DashboardTasksWidget::class)['Due this week'])->toBe('0');
});

test('a local day is bounded in UTC, DST included', function (string $date, string $timezone, string $start, string $end) {
    [$from, $until] = LocalTime::dayBounds($date, $timezone);

    expect($from->format('Y-m-d H:i:s.u'))->toBe($start)
        ->and($until->format('Y-m-d H:i:s.u'))->toBe($end)
        ->and($from->timezoneName)->toBe('UTC')
        ->and($until->timezoneName)->toBe('UTC');
})->with([
    'utc' => ['2026-03-02', 'UTC', '2026-03-02 00:00:00.000000', '2026-03-02 23:59:59.999999'],
    'tokyo' => ['2026-03-02', 'Asia/Tokyo', '2026-03-01 15:00:00.000000', '2026-03-02 14:59:59.999999'],
    'kiritimati' => ['2026-03-02', 'Pacific/Kiritimati', '2026-03-01 10:00:00.000000', '2026-03-02 09:59:59.999999'],
    'paris spring forward' => ['2026-03-29', 'Europe/Paris', '2026-03-28 23:00:00.000000', '2026-03-29 21:59:59.999999'],
    'paris fall back' => ['2026-10-25', 'Europe/Paris', '2026-10-24 22:00:00.000000', '2026-10-25 22:59:59.999999'],
]);

test('a local day without a timezone uses the panel timezone', function () {
    ($this->inZone)('Asia/Tokyo', '2026-03-04 03:00:00');

    expect(LocalTime::dayBounds('2026-03-02')[0]->format('Y-m-d H:i:s'))->toBe('2026-03-01 15:00:00');
});

test('a filter date is only a real Y-m-d date', function (mixed $value, ?string $expected) {
    expect(LocalTime::filterDate($value))->toBe($expected);
})->with([
    'a date' => ['2026-03-02', '2026-03-02'],
    'a leap day' => ['2024-02-29', '2024-02-29'],
    'not a leap day' => ['2026-02-29', null],
    'no zero padding' => ['2026-3-2', null],
    'trailing newline' => ["2026-03-02\n", null],
    'an array' => [['2026-03-02'], null],
    'null' => [null, null],
    'an integer' => [20260302, null],
]);
