<?php

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Filament\Resources\Contacts\Pages\EditContact;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

// Form pre-population
test('edit form is pre-populated with existing contact data', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Alice',
        'email' => 'alice@example.com',
        'status' => ContactStatus::Prospect,
        'phone' => '+1234567890',
        'lead_source' => LeadSource::LinkedIn,
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->assertFormSet([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'status' => ContactStatus::Prospect,
            'phone' => '+1234567890',
            'lead_source' => LeadSource::LinkedIn,
        ]);
});

test('edit form pre-populates custom field values from JSON', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Company',
        'type' => 'text',
        'unique' => false,
        'order' => 1,
    ]);

    $contact = Contact::factory()->withCustomFields([(string) $field->id => 'Acme Corp'])->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->assertFormSet([
            "custom_fields.{$field->id}" => 'Acme Corp',
        ]);
});

// Successful updates
test('user can update contact name', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Old Name',
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh()->name)->toBe('New Name');
});

test('user can update contact email', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'old@example.com',
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['email' => 'new@example.com'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh()->email)->toBe('new@example.com');
});

test('user can update contact tags', function () {
    $tag1 = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);
    $tag2 = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'Newsletter']);

    $contact = Contact::factory()->withTags([$tag1->id])->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['tags' => [$tag2->id]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh()->tags()->pluck('id')->toArray())->toBe([$tag2->id]);
});

test('user can update contact status and lead source', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'status' => ContactStatus::Lead,
        'lead_source' => LeadSource::Website,
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm([
            'status' => ContactStatus::ActiveClient,
            'lead_source' => LeadSource::Referral,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh()->status)->toBe(ContactStatus::ActiveClient)
        ->and($contact->fresh()->lead_source)->toBe(LeadSource::Referral);
});

test('user can clear contact status and lead source', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'status' => ContactStatus::Prospect,
        'lead_source' => LeadSource::Inbound,
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm([
            'status' => null,
            'lead_source' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh()->status)->toBeNull()
        ->and($contact->fresh()->lead_source)->toBeNull();
});

// Validation on update
test('update requires name', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['name' => ''])
        ->call('save')
        ->assertHasFormErrors(['name' => 'required']);
});

test('update requires email', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['email' => ''])
        ->call('save')
        ->assertHasFormErrors(['email' => 'required']);
});

test('update rejects duplicate email in the same organization', function () {
    Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'existing@example.com',
    ]);

    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'mine@example.com',
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['email' => 'existing@example.com'])
        ->call('save')
        ->assertHasFormErrors(['email']);
});

test('update allows keeping the same email (ignoreRecord)', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'mine@example.com',
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['email' => 'mine@example.com'])
        ->call('save')
        ->assertHasNoFormErrors();
});

// Organization-scoped uniqueness on update
test('same email is allowed in a different organization on update', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    Contact::factory()->create([
        'organization_id' => $otherOrg->id,
        'email' => 'cross@example.com',
    ]);

    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'mine@example.com',
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm(['email' => 'cross@example.com'])
        ->call('save')
        ->assertHasNoFormErrors();
});

// Unique custom field ignores self on update
test('unique custom field ignores self on update', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Employee ID',
        'type' => 'text',
        'unique' => true,
        'order' => 1,
    ]);

    $contact = Contact::factory()->withCustomFields([(string) $field->id => 'EMP001'])->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->fillForm([
            "custom_fields.{$field->id}" => 'EMP001',
        ])
        ->call('save')
        ->assertHasNoFormErrors();
});

// Delete action
test('delete action exists on edit page', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->assertActionExists(DeleteAction::class);
});

test('user can delete a contact from the edit page', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(EditContact::class, ['record' => $contact->id])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    expect(Contact::find($contact->id))->toBeNull();
});
