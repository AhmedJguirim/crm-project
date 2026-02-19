<?php

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
    ]);
});

// ── Full form (ViewContact page) ─────────────────────────────────────────────

test('log activity action exists on view page', function () {
    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->assertActionExists('logActivity');
});

test('full form creates activity with all fields', function () {
    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->callAction('logActivity', [
            'type' => ActivityType::Call->value,
            'occurred_at' => '2026-02-15 10:00:00',
            'duration_minutes' => 30,
            'subject' => 'Quarterly review',
            'notes' => 'Discussed project timeline.',
            'outcome' => ActivityOutcome::Positive->value,
            'create_follow_up' => true,
            'follow_up_at' => '2026-02-22 10:00:00',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $activity = Activity::where('contact_id', $this->contact->id)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe(ActivityType::Call)
        ->and($activity->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-02-15 10:00:00')
        ->and($activity->duration_minutes)->toBe(30)
        ->and($activity->subject)->toBe('Quarterly review')
        ->and($activity->notes)->toBe('Discussed project timeline.')
        ->and($activity->outcome)->toBe(ActivityOutcome::Positive)
        ->and($activity->follow_up_at->format('Y-m-d H:i:s'))->toBe('2026-02-22 10:00:00')
        ->and($activity->user_id)->toBe($this->user->id)
        ->and($activity->organization_id)->toBe($this->org->id);
});

test('full form creates activity with minimal fields', function () {
    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->callAction('logActivity', [
            'type' => ActivityType::Email->value,
            'occurred_at' => '2026-02-15 10:00:00',
            'create_follow_up' => false,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $activity = Activity::where('contact_id', $this->contact->id)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe(ActivityType::Email)
        ->and($activity->duration_minutes)->toBeNull()
        ->and($activity->subject)->toBeNull()
        ->and($activity->notes)->toBeNull()
        ->and($activity->outcome)->toBeNull()
        ->and($activity->follow_up_at)->toBeNull();
});

test('full form clears follow_up_at when create_follow_up is false', function () {
    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->callAction('logActivity', [
            'type' => ActivityType::Note->value,
            'occurred_at' => '2026-02-15 10:00:00',
            'create_follow_up' => false,
            'follow_up_at' => '2026-02-22 10:00:00',
        ])
        ->assertHasNoActionErrors();

    $activity = Activity::where('contact_id', $this->contact->id)->first();

    expect($activity->follow_up_at)->toBeNull();
});

test('full form validates required fields', function () {
    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->callAction('logActivity', [
            'type' => null,
            'occurred_at' => null,
            'create_follow_up' => false,
        ])
        ->assertHasActionErrors([
            'type' => 'required',
            'occurred_at' => 'required',
        ]);

    expect(Activity::where('contact_id', $this->contact->id)->count())->toBe(0);
});

test('full form auto-assigns organization via observer', function () {
    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->callAction('logActivity', [
            'type' => ActivityType::Note->value,
            'occurred_at' => '2026-02-15 10:00:00',
            'create_follow_up' => false,
        ])
        ->assertHasNoActionErrors();

    $activity = Activity::where('contact_id', $this->contact->id)->first();

    expect($activity->organization_id)->toBe($this->org->id);
});

// ── Quick form (table action) ────────────────────────────────────────────────

test('quick log activity action exists on table', function () {
    Livewire::test(ListContacts::class)
        ->assertTableActionExists('logActivity');
});

test('quick form creates activity from table', function () {
    Livewire::test(ListContacts::class)
        ->callTableAction('logActivity', $this->contact, [
            'type' => ActivityType::WhatsApp->value,
            'occurred_at' => '2026-02-15 14:00:00',
            'notes' => 'Quick check-in via WhatsApp.',
        ])
        ->assertHasNoTableActionErrors()
        ->assertNotified();

    $activity = Activity::where('contact_id', $this->contact->id)->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe(ActivityType::WhatsApp)
        ->and($activity->notes)->toBe('Quick check-in via WhatsApp.')
        ->and($activity->user_id)->toBe($this->user->id)
        ->and($activity->organization_id)->toBe($this->org->id);
});

test('quick form validates required fields', function () {
    Livewire::test(ListContacts::class)
        ->callTableAction('logActivity', $this->contact, [
            'type' => null,
            'occurred_at' => null,
        ])
        ->assertHasTableActionErrors([
            'type' => 'required',
            'occurred_at' => 'required',
        ]);

    expect(Activity::where('contact_id', $this->contact->id)->count())->toBe(0);
});

// ── Timeline widget ──────────────────────────────────────────────────────────

test('timeline widget renders on view page', function () {
    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->assertOk();
});

test('activity feed displays activities', function () {
    Activity::factory()->count(3)->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => $this->user->id,
    ]);

    Livewire::test(ContactActivityFeed::class, ['record' => $this->contact])
        ->assertOk()
        ->assertSee('Activity Log')
        ->assertDontSee('No activities logged yet');
});

test('activity feed shows empty state when no activities', function () {
    Livewire::test(ContactActivityFeed::class, ['record' => $this->contact])
        ->assertOk()
        ->assertSee('No activities logged yet');
});

test('activity feed shows activity details', function () {
    Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => $this->user->id,
        'type' => ActivityType::Call,
        'subject' => 'Important discussion',
        'notes' => 'Talked about the project roadmap.',
    ]);

    Livewire::test(ContactActivityFeed::class, ['record' => $this->contact])
        ->assertSee('Important discussion')
        ->assertSee('Talked about the project roadmap.');
});

test('activity feed shows outcome and duration', function () {
    Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => $this->user->id,
        'type' => ActivityType::Meeting,
        'subject' => 'Sprint planning',
        'duration_minutes' => 60,
        'outcome' => ActivityOutcome::Positive,
    ]);

    Livewire::test(ContactActivityFeed::class, ['record' => $this->contact])
        ->assertSee('Sprint planning')
        ->assertSee('Meeting')
        ->assertSee('Positive')
        ->assertDontSee('No activities logged yet');
});

test('activity feed date filters scope results', function () {
    Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => $this->user->id,
        'type' => ActivityType::Call,
        'subject' => 'Old call',
        'occurred_at' => '2025-01-10 10:00:00',
    ]);

    Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->contact->id,
        'user_id' => $this->user->id,
        'type' => ActivityType::Email,
        'subject' => 'Recent email',
        'occurred_at' => '2026-02-01 10:00:00',
    ]);

    Livewire::test(ContactActivityFeed::class, ['record' => $this->contact])
        ->set('dateFrom', '2026-01-01')
        ->assertSee('Recent email')
        ->assertDontSee('Old call');
});

// ── Organization isolation ───────────────────────────────────────────────────

test('activity is scoped to the contact organization', function () {
    Livewire::test(ViewContact::class, ['record' => $this->contact->id])
        ->callAction('logActivity', [
            'type' => ActivityType::Email->value,
            'occurred_at' => '2026-02-15 10:00:00',
            'create_follow_up' => false,
        ])
        ->assertHasNoActionErrors();

    $activity = Activity::where('contact_id', $this->contact->id)->first();

    expect($activity->organization_id)->toBe($this->org->id);
});

// ── Enum tests ───────────────────────────────────────────────────────────────

test('activity type enum has duration flag', function () {
    expect(ActivityType::Call->hasDuration())->toBeTrue()
        ->and(ActivityType::Meeting->hasDuration())->toBeTrue()
        ->and(ActivityType::InPerson->hasDuration())->toBeTrue()
        ->and(ActivityType::Email->hasDuration())->toBeFalse()
        ->and(ActivityType::Note->hasDuration())->toBeFalse()
        ->and(ActivityType::WhatsApp->hasDuration())->toBeFalse();
});

test('activity type enum has labels, colors, and icons', function () {
    foreach (ActivityType::cases() as $type) {
        expect($type->getLabel())->toBeString()
            ->and($type->getColor())->toBeString()
            ->and($type->getIcon())->not->toBeNull();
    }
});

test('activity outcome enum has labels and colors', function () {
    foreach (ActivityOutcome::cases() as $outcome) {
        expect($outcome->getLabel())->toBeString()
            ->and($outcome->getColor())->toBeString();
    }
});
