<?php

use App\Enums\ActivityType;
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
use App\Services\Deals\DealStageMover;
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

    $deal = Deal::factory()->inStage('Proposal Sent')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
        'title' => 'Website Revamp Retainer',
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

test('the deal feed lists a deal-only activity and its author', function () {
    $solo = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Solo']);

    Activity::factory()->onlyOnDeal($solo)->create([
        'user_id' => $this->user->id,
        'type' => ActivityType::Meeting,
        'subject' => 'Scope review',
        'occurred_at' => '2026-02-20 09:00:00',
    ]);

    $feed = Livewire::test(DealActivityFeed::class, ['record' => $solo])
        ->assertSuccessful()
        ->assertSee('Scope review')
        ->assertSee('Logged by '.$this->user->name);

    expect($feed->html())->toMatch('/fi-badge-label">\s*Meeting\s*</');
});

test('the deal feed shows only that deal activities', function () {
    $solo = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Solo']);
    $retainer = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Retainer']);

    Activity::factory()->onlyOnDeal($solo)->create(['subject' => 'Solo note', 'type' => ActivityType::Note]);
    Activity::factory()->onlyOnDeal($retainer)->create(['subject' => 'Retainer note', 'type' => ActivityType::Note]);

    Livewire::test(DealActivityFeed::class, ['record' => $solo])
        ->assertSee('Solo note')
        ->assertDontSee('Retainer note');
});

test('another organization deal-only activity never shows in the feed', function () {
    $solo = Deal::factory()->create(['organization_id' => $this->org->id, 'contact_id' => null, 'title' => 'Solo']);
    $otherOrg = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
    $globexDeal = Deal::factory()->create(['organization_id' => $otherOrg->id, 'contact_id' => null, 'title' => 'Globex deal']);

    Activity::factory()->onlyOnDeal($globexDeal)->create(['subject' => 'Globex secret', 'type' => ActivityType::Note]);

    Livewire::test(DealActivityFeed::class, ['record' => $solo])
        ->assertDontSee('Globex secret');
});

test('move to won action updates status stage and won date', function () {
    $deal = Deal::factory()->inStage('Negotiating')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'won_at' => null,
        'lost_at' => null,
    ]);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('moveToWon')
        ->assertHasNoActionErrors();

    $deal->refresh();

    expect($deal->status)->toBe(DealStatus::Won)
        ->and($deal->pipelineStage->name)->toBe('Won')
        ->and($deal->won_at)->not->toBeNull()
        ->and($deal->lost_at)->toBeNull();

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->assertDontSee('Move to Won')
        ->assertDontSee('Move to Lost');
});

test('move to lost action updates status stage and lost date', function () {
    $deal = Deal::factory()->inStage('Negotiating')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'won_at' => null,
        'lost_at' => null,
    ]);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction('moveToLost')
        ->assertHasNoActionErrors();

    $deal->refresh();

    expect($deal->status)->toBe(DealStatus::Lost)
        ->and($deal->pipelineStage->name)->toBe('Lost')
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

test('the deal page action tells the details panel the deal changed', function (string $action) {
    $deal = Deal::factory()->inStage('Negotiating')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'title' => 'Panel Refresh Deal',
        'status' => DealStatus::Open,
        'won_at' => null,
        'lost_at' => null,
    ]);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->callAction($action)
        ->assertHasNoActionErrors()
        ->assertDispatched('dealUpdated')
        ->assertNotDispatched('activityLogged');
})->with(['moveToWon', 'moveToLost']);

test('the details panel listens to dealUpdated', function () {
    $deal = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id]);

    expect(Livewire::test(DealDetailsWidget::class, ['record' => $deal])->effects['listeners'])->toContain('dealUpdated');
});

test('the details panel shows the new stage and status after dealUpdated', function (string $stage) {
    $deal = Deal::factory()->inStage('Negotiating')->create([
        'organization_id' => $this->org->id,
        'created_by' => $this->user->id,
        'status' => DealStatus::Open,
        'won_at' => null,
        'lost_at' => null,
    ]);

    $widget = Livewire::test(DealDetailsWidget::class, ['record' => $deal])
        ->assertSee('Negotiating')
        ->assertSee('Open');

    DealStageMover::move($deal->fresh(), pipelineStageNamed($this->org->id, $stage));

    $widget->dispatch('dealUpdated')
        ->assertSee($stage)
        ->assertDontSee('Negotiating')
        ->assertDontSee('Open');
})->with(['Won', 'Lost']);

test('edit on the deal page is a link to the edit page, not a modal', function () {
    $deal = Deal::factory()->inStage('Negotiating')->create(['organization_id' => $this->org->id, 'created_by' => $this->user->id]);

    Livewire::test(ViewDeal::class, ['record' => $deal->id])
        ->assertActionHasUrl('edit', DealResource::getUrl('edit', ['record' => $deal]));
});
