<?php

use App\Enums\ContactStatus;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('bulk change status updates selected contacts', function () {
    $contacts = Contact::factory()->count(3)->create([
        'organization_id' => $this->org->id,
        'status' => ContactStatus::Lead,
    ]);

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('changeStatus', $contacts, [
            'status' => ContactStatus::ActiveClient->value,
        ]);

    $contacts->each(fn (Contact $contact) => expect($contact->fresh()->status)->toBe(ContactStatus::ActiveClient));
});

test('bulk add tags attaches tags to selected contacts', function () {
    $contacts = Contact::factory()->count(2)->create([
        'organization_id' => $this->org->id,
    ]);

    $tag = Tag::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('addTags', $contacts, [
            'tags' => [$tag->id],
        ]);

    $contacts->each(fn (Contact $contact) => expect($contact->fresh()->tags->pluck('id')->toArray())->toContain($tag->id));
});

test('bulk add tags does not duplicate existing tags', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    $tag = Tag::factory()->create(['organization_id' => $this->org->id]);
    $contact->tags()->attach($tag);

    Livewire::test(ListContacts::class)
        ->callTableBulkAction('addTags', collect([$contact]), [
            'tags' => [$tag->id],
        ]);

    expect($contact->fresh()->tags)->toHaveCount(1);
});
