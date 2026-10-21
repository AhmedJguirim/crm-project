<?php

use App\Enums\ActivityType;
use App\Enums\ContactStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TaskStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\ContactsOverviewWidget;
use App\Filament\Widgets\DealsOverviewWidget;
use App\Filament\Widgets\KpiOverviewWidget;
use App\Filament\Widgets\MoneyOwedWidget;
use App\Filament\Widgets\MyDealsClosingWidget;
use App\Filament\Widgets\MyTasksWidget;
use App\Filament\Widgets\OverdueInvoicesWidget;
use App\Filament\Widgets\RecentActivitiesWidget;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Livewire\invade;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 12:15:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('all business overview widgets render with organization data', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'status' => ContactStatus::ActiveClient,
    ]);

    Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
    ]);

    Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'status' => InvoiceStatus::Paid,
        'paid_at' => now()->subDay(),
    ]);

    Task::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
        'status' => TaskStatus::Pending,
        'due_at' => now()->subDay(),
    ]);

    Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'user_id' => $this->user->id,
        'type' => ActivityType::Call,
        'subject' => 'Kickoff sync',
        'occurred_at' => now()->subHour(),
    ]);

    Livewire::test(KpiOverviewWidget::class)
        ->assertSee('Key figures');

    Livewire::test(DealsOverviewWidget::class)
        ->assertSee('Open Deals');

    Livewire::test(KpiOverviewWidget::class)
        ->assertSee('Cash Collected This Month');

    Livewire::test(MoneyOwedWidget::class)
        ->assertSee('Money owed');

    Livewire::test(OverdueInvoicesWidget::class)
        ->assertSee('Oldest overdue invoices');

    Livewire::test(ContactsOverviewWidget::class)
        ->assertSee('Total Contacts');

    Livewire::test(MyTasksWidget::class)
        ->assertSee('My tasks');

    Livewire::test(MyDealsClosingWidget::class)
        ->assertSee('My deals closing');

    Livewire::test(RecentActivitiesWidget::class)
        ->assertSee('Recent Activities')
        ->assertSee('Kickoff sync');
});

test('recent activities widget shows latest 10 items in descending order', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    foreach (range(1, 12) as $index) {
        Activity::factory()->create([
            'organization_id' => $this->org->id,
            'contact_id' => $contact->id,
            'user_id' => $this->user->id,
            'type' => ActivityType::Note,
            'subject' => sprintf('Activity %02d', $index),
            'occurred_at' => now()->subMinutes(12 - $index),
        ]);
    }

    Livewire::test(RecentActivitiesWidget::class)
        ->assertSeeInOrder(['Activity 12', 'Activity 11', 'Activity 10'])
        ->assertDontSee('Activity 01')
        ->assertDontSee('Activity 02');
});

test('the Filament default cards are gone from the dashboard', function () {
    $widgets = Livewire::test(Dashboard::class)->instance()->getWidgets();

    expect($widgets)->not->toContain(AccountWidget::class)
        ->and($widgets)->not->toContain(FilamentInfoWidget::class)
        ->and($widgets)->toContain(KpiOverviewWidget::class)
        ->and($widgets)->toContain(MyTasksWidget::class);
});

test('only my tasks refreshes on its own', function (string $widget) {
    expect(invade(Livewire::test($widget)->instance())->getPollingInterval())->toBeNull();
})->with([
    DealsOverviewWidget::class,
    ContactsOverviewWidget::class,
    KpiOverviewWidget::class,
    MoneyOwedWidget::class,
]);
