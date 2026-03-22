<?php

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('activity belongs to deal', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
    ]);

    $activity = Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'user_id' => $this->user->id,
        'deal_id' => $deal->id,
        'type' => ActivityType::Call,
        'occurred_at' => now(),
    ]);

    expect($activity->deal)->not->toBeNull()
        ->and($activity->deal->id)->toBe($deal->id)
        ->and($activity->deal->title)->toBe($deal->title);
});

test('activity deal relationship is null when no deal linked', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $activity = Activity::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'user_id' => $this->user->id,
        'deal_id' => null,
        'type' => ActivityType::Note,
        'occurred_at' => now(),
    ]);

    expect($activity->deal)->toBeNull();
});

test('deal has many activities through deal_id', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    $deal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'created_by' => $this->user->id,
    ]);

    Activity::factory()->count(3)->create([
        'organization_id' => $this->org->id,
        'contact_id' => $contact->id,
        'user_id' => $this->user->id,
        'deal_id' => $deal->id,
        'type' => ActivityType::Email,
        'occurred_at' => now(),
    ]);

    expect(Activity::where('deal_id', $deal->id)->count())->toBe(3);
});
