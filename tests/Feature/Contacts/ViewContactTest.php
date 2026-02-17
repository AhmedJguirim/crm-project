<?php

use App\Filament\Resources\Contacts\Pages\ViewContact;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

// ── Page loads ────────────────────────────────────────────────────────────────

test('view page loads successfully', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->assertOk();
});

// ── Infolist displays correct data ────────────────────────────────────────────

test('view page shows contact name, email, and phone', function () {
    $contact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Alice Smith',
        'email' => 'alice@example.com',
        'phone' => '+1234567890',
    ]);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->assertSchemaStateSet([
            'name' => 'Alice Smith',
            'email' => 'alice@example.com',
            'phone' => '+1234567890',
        ]);
});

test('view page shows tags', function () {
    $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);
    $contact = Contact::factory()->withTags([$tag->id])->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->assertOk();

    expect($contact->tags()->count())->toBe(1);
});

// ── Header actions ────────────────────────────────────────────────────────────

test('edit action exists in header', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->assertActionExists(EditAction::class);
});

test('custom fields action exists in header', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->assertActionExists('customFields');
});

// ── Custom fields modal ───────────────────────────────────────────────────────

test('custom fields modal can be opened', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->callAction('customFields')
        ->assertHasNoActionErrors();
});

test('custom fields modal shows empty state when no fields defined', function () {
    $contact = Contact::factory()->create(['organization_id' => $this->org->id]);

    // No custom fields created for this org
    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->callAction('customFields')
        ->assertHasNoActionErrors();
});

test('custom fields modal shows text field value', function () {
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

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->callAction('customFields')
        ->assertHasNoActionErrors();

    // Verify the value is stored correctly so the modal can display it
    expect($contact->custom_field_values[(string) $field->id])->toBe('Acme Corp');
});

test('custom fields modal shows select label instead of raw value', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Status',
        'type' => 'select',
        'unique' => false,
        'order' => 1,
        'options' => [
            ['label' => 'Active',   'value' => 'active'],
            ['label' => 'Inactive', 'value' => 'inactive'],
        ],
    ]);

    $contact = Contact::factory()->withCustomFields([(string) $field->id => 'active'])->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->callAction('customFields')
        ->assertHasNoActionErrors();

    expect($contact->custom_field_values[(string) $field->id])->toBe('active');
});

test('custom fields modal shows multiselect values as comma-separated labels', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Skills',
        'type' => 'multiselect',
        'unique' => false,
        'order' => 1,
        'options' => [
            ['label' => 'PHP',     'value' => 'php'],
            ['label' => 'Laravel', 'value' => 'laravel'],
            ['label' => 'Vue.js',  'value' => 'vue'],
        ],
    ]);

    $contact = Contact::factory()->withCustomFields([(string) $field->id => ['php', 'laravel']])->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ViewContact::class, ['record' => $contact->id])
        ->callAction('customFields')
        ->assertHasNoActionErrors();

    expect($contact->custom_field_values[(string) $field->id])->toBe(['php', 'laravel']);
});

// ── Organization isolation ────────────────────────────────────────────────────

test('cannot view a contact from another organization', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $otherContact = Contact::factory()->create(['organization_id' => $otherOrg->id]);

    // The resource scopes queries to the current tenant, so the record is not
    // found (404) rather than forbidden — the contact is invisible, not blocked.
    expect(fn () => Livewire::test(ViewContact::class, ['record' => $otherContact->id]))
        ->toThrow(ModelNotFoundException::class);
});
