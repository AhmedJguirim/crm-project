<?php

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Filament\Resources\Contacts\Pages\CreateContact;
use App\Models\Contact;
use App\Models\CustomField;
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

// Successful creation
test('user can create a contact with name and email only', function () {
    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $contact = Contact::where('email', 'john@example.com')->first();

    expect($contact)->not->toBeNull()
        ->and($contact->organization_id)->toBe($this->org->id)
        ->and($contact->name)->toBe('John Doe')
        ->and($contact->phone)->toBeNull();
});

test('user can create a contact with all fields', function () {
    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'status' => ContactStatus::Prospect,
            'phone' => '+1234567890',
            'lead_source' => LeadSource::Referral,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $contact = Contact::where('email', 'jane@example.com')->first();

    expect($contact->phone)->toBe('+1234567890')
        ->and($contact->status)->toBe(ContactStatus::Prospect)
        ->and($contact->lead_source)->toBe(LeadSource::Referral);
});

test('create form defaults status to lead', function () {
    Livewire::test(CreateContact::class)
        ->assertFormSet([
            'status' => ContactStatus::Lead,
        ]);
});

test('user can create a contact with null status and null lead source', function () {
    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'Null Lifecycle Contact',
            'email' => 'null-lifecycle@example.com',
            'status' => null,
            'lead_source' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $contact = Contact::where('email', 'null-lifecycle@example.com')->first();

    expect($contact->status)->toBeNull()
        ->and($contact->lead_source)->toBeNull();
});

test('user can create a contact with tags', function () {
    $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);

    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'Tagged Contact',
            'email' => 'tagged@example.com',
            'tags' => [$tag->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $contact = Contact::where('email', 'tagged@example.com')->first();

    expect($contact->tags()->count())->toBe(1)
        ->and($contact->tags()->first()->id)->toBe($tag->id);
});

test('created contact is scoped to the current organization via observer', function () {
    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'Scoped Contact',
            'email' => 'scoped@example.com',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contact::where('email', 'scoped@example.com')->first()->organization_id)->toBe($this->org->id);
});

// Validation
test('creation requires name', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['name' => '', 'email' => 'test@example.com'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

test('creation requires email', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Test', 'email' => ''])
        ->call('create')
        ->assertHasFormErrors(['email' => 'required']);
});

test('creation rejects invalid email', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Test', 'email' => 'not-an-email'])
        ->call('create')
        ->assertHasFormErrors(['email' => 'email']);
});

test('name cannot exceed 255 characters', function () {
    Livewire::test(CreateContact::class)
        ->fillForm(['name' => str_repeat('a', 256), 'email' => 'test@example.com'])
        ->call('create')
        ->assertHasFormErrors(['name' => 'max']);
});

test('creation rejects duplicate email in the same organization', function () {
    Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'existing@example.com',
    ]);

    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Dup', 'email' => 'existing@example.com'])
        ->call('create')
        ->assertHasFormErrors(['email']);

    expect(Contact::where('email', 'existing@example.com')->count())->toBe(1);
});

// Organization-scoped uniqueness
test('same email is allowed in a different organization', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    Contact::factory()->create([
        'organization_id' => $otherOrg->id,
        'email' => 'shared@example.com',
    ]);

    Livewire::test(CreateContact::class)
        ->fillForm(['name' => 'Cross Org', 'email' => 'shared@example.com'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contact::withoutGlobalScope('organization')->where('email', 'shared@example.com')->count())->toBe(2);
});

// Custom field unique constraint
test('unique custom field is validated on create', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Employee ID',
        'type' => 'text',
        'unique' => true,
        'order' => 1,
    ]);

    Contact::factory()->withCustomFields([(string) $field->id => 'EMP001'])->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'New Contact',
            'email' => 'new@example.com',
            "custom_fields.{$field->id}" => 'EMP001',
        ])
        ->call('create')
        ->assertHasFormErrors(["custom_fields.{$field->id}"]);
});

test('multiselect custom field is stored as array', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Interests',
        'type' => 'multiselect',
        'unique' => false,
        'options' => [
            ['label' => 'Sports', 'value' => 'sports'],
            ['label' => 'Tech', 'value' => 'tech'],
        ],
        'order' => 1,
    ]);

    Livewire::test(CreateContact::class)
        ->fillForm([
            'name' => 'Multi Contact',
            'email' => 'multi@example.com',
            "custom_fields.{$field->id}" => ['sports', 'tech'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $contact = Contact::where('email', 'multi@example.com')->first();

    expect($contact->custom_field_values[(string) $field->id])->toBe(['sports', 'tech']);
});
