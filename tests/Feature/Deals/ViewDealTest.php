<?php

use App\Enums\ActivityType;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\ViewDeal;
use App\Filament\Resources\Deals\Widgets\DealActivityFeed;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-02-21 11:30:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('deal detail page renders deal metadata and contact activities', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Alice Smith',
    ]);

    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
        'title' => 'Website Revamp Retainer',
        'stage' => DealStage::ProposalSent,
        'status' => DealStatus::Open,
        'value' => 5000,
        'notes' => 'Proposal sent, waiting for signature.',
    ]);

    Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'deal_id' => $deal->id,
        'user_id' => $this->user->id,
        'type' => ActivityType::Call,
        'subject' => 'Negotiation call',
    ]);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->assertSuccessful()
        ->assertSee('Website Revamp Retainer');

    Livewire::test(DealDetailsWidget::class, ['record' => $deal])
        ->assertSuccessful()
        ->assertSee('Proposal Sent')
        ->assertSee('$5,000.00')
        ->assertSee('Alice Smith')
        ->assertSee('Proposal sent, waiting for signature.');

    Livewire::test(DealActivityFeed::class, ['record' => $deal])
        ->assertSuccessful()
        ->assertSee('Negotiation call');
});

test('move to won action updates status stage and won date', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Negotiating,
        'status' => DealStatus::Open,
        'won_at' => null,
        'lost_at' => null,
    ]);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('moveToWon')
        ->assertHasNoActionErrors();

    $deal->refresh();

    expect($deal->status)->toBe(DealStatus::Won)
        ->and($deal->stage)->toBe(DealStage::Won)
        ->and($deal->won_at)->not->toBeNull()
        ->and($deal->lost_at)->toBeNull();

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->assertDontSee('Move to Won')
        ->assertDontSee('Move to Lost');
});

test('move to lost action updates status stage and lost date', function () {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'stage' => DealStage::Negotiating,
        'status' => DealStatus::Open,
        'won_at' => null,
        'lost_at' => null,
    ]);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('moveToLost')
        ->assertHasNoActionErrors();

    $deal->refresh();

    expect($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->stage)->toBe(DealStage::Lost)
        ->and($deal->lost_at)->not->toBeNull()
        ->and($deal->won_at)->toBeNull();
});

test('log activity action creates activity linked to deal contact', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
    ]);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('logActivity', data: [
            'type' => ActivityType::Call,
            'occurred_at' => now()->toDateTimeString(),
            'subject' => 'Deal follow-up',
            'notes' => 'Client asked for one final clarification.',
        ])
        ->assertHasNoActionErrors();

    $activity = Activity::query()->where('subject', 'Deal follow-up')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->contact_id)->toBe($contact->id)
        ->and($activity->user_id)->toBe($this->user->id);
});

test('breadcrumbs include pipeline contact and deal title', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Alice Smith',
    ]);

    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
        'title' => 'Mobile App Discovery',
    ]);

    $component = Livewire::test(ViewDeal::class, ['record' => $deal->id]);

    expect($component->instance()->getBreadcrumbs())
        ->toBe([
            DealResource::getUrl('index') => 'Pipeline',
            ContactResource::getUrl('view', ['record' => $contact]) => 'Alice Smith',
            'Mobile App Discovery',
        ]);
});

test('cannot view a deal from another organization', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $otherDeal = Deal::factory()->create([
        'organization_id' => $otherOrg->id,
        'created_by' => $otherUser->id,
    ]);

    $this->get(DealResource::getUrl('view', ['record' => $otherDeal->id]))
        ->assertNotFound();
});

test('the deal page flags only an open deal whose close date is before today as overdue', function (DealStatus $status, string $date, bool $overdue) {
    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => $status,
        'expected_close_date' => $date,
    ]);

    $widget = Livewire::test(DealDetailsWidget::class, ['record' => $deal]);

    $overdue ? $widget->assertSee('Overdue') : $widget->assertDontSee('Overdue');
})->with([
    'open, yesterday' => [DealStatus::Open, '2026-02-20', true],
    'won, yesterday' => [DealStatus::Won, '2026-02-20', false],
    'open, today' => [DealStatus::Open, '2026-02-21', false],
]);
