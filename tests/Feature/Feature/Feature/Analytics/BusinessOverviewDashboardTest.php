<?php

use App\Enums\ActivityType;
use App\Enums\ContactStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TaskStatus;
use App\Filament\Widgets\ContactsOverviewWidget;
use App\Filament\Widgets\DashboardTasksWidget;
use App\Filament\Widgets\DealsOverviewWidget;
use App\Filament\Widgets\RecentActivitiesWidget;
use App\Filament\Widgets\RevenueOverviewWidget;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

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

    Livewire::test(DealsOverviewWidget::class)
        ->assertSee('Open Deals');

    Livewire::test(RevenueOverviewWidget::class)
        ->assertSee('Paid This Month');

    Livewire::test(ContactsOverviewWidget::class)
        ->assertSee('Total Contacts');

    Livewire::test(DashboardTasksWidget::class)
        ->assertSee('Overdue');

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
