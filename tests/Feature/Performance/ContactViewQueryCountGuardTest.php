<?php

use App\Enums\ActivityType;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Contacts\Widgets\ContactDetailsWidget;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Carbon::setTestNow('2026-11-25 12:00:00');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Guard Person',
        'email' => 'guard.person@example.test',
        'owner_id' => $this->user->id,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Gives the contact one tag, one custom field value, one activity by its own user and one segment per number.
 */
function guardedContactDetails(int $from, int $to): void
{
    $contact = test()->contact;
    $organization = test()->org;

    foreach (range($from, $to) as $i) {
        $tag = Tag::factory()->for($organization)->create(['name' => "Guard Tag {$i}"]);
        $contact->tags()->attach($tag);

        $field = CustomField::factory()->create([
            'organization_id' => $organization->id,
            'name' => "Guard Field {$i}",
            'type' => 'text',
            'unique' => false,
            'order' => $i,
        ]);
        $contact->refresh();
        $contact->update([
            'custom_field_values' => [...($contact->custom_field_values ?? []), $field->key => "Guard value {$i}"],
        ]);

        $user = User::factory()->create(['name' => "Guard User {$i}"]);
        Activity::factory()->type(ActivityType::Call)->create([
            'organization_id' => $organization->id,
            'contact_id' => $contact->id,
            'user_id' => $user->id,
            'subject' => "Guard Activity {$i}",
            'occurred_at' => now()->subHours($i),
        ]);

        $segment = Segment::factory()->for($organization)->create(['name' => "Guard Segment {$i}"]);
        $contact->segments()->attach($segment);
    }
}

test('the contact view runs the same number of queries with 2 and 9 related records', function (Closure $target, array $proof) {
    $contactId = $this->contact->id;
    $call = $target();

    guardedContactDetails(1, 2);

    $call(Contact::query()->findOrFail($contactId), []);

    $fresh = Contact::query()->findOrFail($contactId);
    $small = queryCountOf(fn () => $call($fresh, []));

    guardedContactDetails(3, 9);

    $fresh = Contact::query()->findOrFail($contactId);
    $large = queryCountOf(fn () => $call($fresh, $proof));

    expect($small)->toBeGreaterThan(0)
        ->and($large)->toBe($small);
})->with([
    'view page' => [
        fn (): Closure => fn (Contact $fresh, array $proof) => test()->get(ContactResource::getUrl('view', ['record' => $fresh->id]))
            ->assertSuccessful()
            ->assertSee('Guard Person'),
        ['Guard Person'],
    ],
    'details widget' => [
        fn (): Closure => function (Contact $fresh, array $proof) {
            $component = Livewire::test(ContactDetailsWidget::class, ['record' => $fresh]);

            foreach ($proof as $text) {
                $component->assertSee($text);
            }

            return $component;
        },
        ['Guard Tag 9', 'Guard Segment 9', 'Guard value 9'],
    ],
    'activity feed' => [
        fn (): Closure => function (Contact $fresh, array $proof) {
            $component = Livewire::test(ContactActivityFeed::class, ['record' => $fresh]);

            foreach ($proof as $text) {
                $component->assertSee($text);
            }

            return $component;
        },
        ['Guard Activity 9', 'Logged by Guard User 9'],
    ],
]);
