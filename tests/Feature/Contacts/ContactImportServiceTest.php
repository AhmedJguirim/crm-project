<?php

use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactImportService;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->service = new ContactImportService($this->org->id);
});

// Successful processing
test('valid row creates a contact', function () {
    $result = $this->service->processRow([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '+1234567890',
        'tags' => '',
    ], []);

    expect($result['success'])->toBeTrue();

    $contact = Contact::where('email', 'john@example.com')->first();
    expect($contact)->not->toBeNull()
        ->and($contact->name)->toBe('John Doe')
        ->and($contact->phone)->toBe('+1234567890')
        ->and($contact->organization_id)->toBe($this->org->id);
});

test('duplicate email returns error', function () {
    Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'existing@example.com',
        'name' => 'Old Name',
    ]);

    $result = $this->service->processRow([
        'name' => 'New Name',
        'email' => 'existing@example.com',
        'phone' => '',
        'tags' => '',
    ], []);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('existing@example.com')
        ->and($result['error'])->toContain('already exists');

    expect(Contact::where('email', 'existing@example.com')->count())->toBe(1);
    expect(Contact::where('email', 'existing@example.com')->first()->name)->toBe('Old Name');
});

// Validation errors
test('missing name returns error', function () {
    $result = $this->service->processRow([
        'name' => '',
        'email' => 'test@example.com',
        'tags' => '',
    ], []);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('Name is required.');
});

test('missing email returns error', function () {
    $result = $this->service->processRow([
        'name' => 'Test',
        'email' => '',
        'tags' => '',
    ], []);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toBe('Email is required.');
});

test('invalid email returns error', function () {
    $result = $this->service->processRow([
        'name' => 'Test',
        'email' => 'not-an-email',
        'tags' => '',
    ], []);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Invalid email');
});

// Tag auto-creation — tags are semicolon-separated
test('tags are auto-created and synced using semicolon separator', function () {
    $result = $this->service->processRow([
        'name' => 'Tagged',
        'email' => 'tagged@example.com',
        'tags' => 'VIP;Newsletter',
    ], []);

    expect($result['success'])->toBeTrue();

    $contact = Contact::where('email', 'tagged@example.com')->first();
    expect($contact->tags()->count())->toBe(2);

    $tagNames = $contact->tags()->pluck('name')->toArray();
    expect($tagNames)->toContain('VIP')
        ->toContain('Newsletter');
});

test('single tag without separator works', function () {
    $result = $this->service->processRow([
        'name' => 'Tagged',
        'email' => 'tagged@example.com',
        'tags' => 'VIP',
    ], []);

    expect($result['success'])->toBeTrue();

    $contact = Contact::where('email', 'tagged@example.com')->first();
    expect($contact->tags()->count())->toBe(1);
});

test('existing tags are reused instead of duplicated', function () {
    Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'Existing']);

    $this->service->processRow([
        'name' => 'Contact',
        'email' => 'contact@example.com',
        'tags' => 'Existing',
    ], []);

    expect(Tag::where('organization_id', $this->org->id)->where('name', 'Existing')->count())->toBe(1);
});

// Custom field processing
test('text custom field value is stored', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Company',
        'type' => 'text',
        'unique' => false,
        'order' => 1,
    ]);

    $this->service->processRow([
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => '',
        'Company' => 'Acme Corp',
    ], [$field->name => $field]);

    $contact = Contact::where('email', 'test@example.com')->first();
    expect($contact->custom_field_values[$field->key])->toBe('Acme Corp');
});

test('number custom field is cast to float', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Score',
        'type' => 'number',
        'unique' => false,
        'order' => 1,
    ]);

    $this->service->processRow([
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => '',
        'Score' => '42.5',
    ], [$field->name => $field]);

    $contact = Contact::where('email', 'test@example.com')->first();
    expect($contact->custom_field_values[$field->key])->toBe(42.5);
});

test('non-numeric number field value returns error', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Score',
        'type' => 'number',
        'unique' => false,
        'order' => 1,
    ]);

    $result = $this->service->processRow([
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => '',
        'Score' => 'abc',
    ], [$field->name => $field]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Score');
});

test('date custom field parsed with d-m-Y format is stored', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Birthday',
        'type' => 'date',
        'unique' => false,
        'order' => 1,
    ]);

    $this->service->processRow([
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => '',
        'Birthday' => '15-01-2024',
    ], [$field->name => $field]);

    $contact = Contact::where('email', 'test@example.com')->first();
    expect($contact->custom_field_values[$field->key])->toBe('2024-01-15');
});

test('invalid date format returns error', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Birthday',
        'type' => 'date',
        'unique' => false,
        'order' => 1,
    ]);

    $result = $this->service->processRow([
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => '',
        'Birthday' => 'not-a-date',
    ], [$field->name => $field]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Birthday');
});

test('unique field collision returns error', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Employee ID',
        'type' => 'text',
        'unique' => true,
        'order' => 1,
    ]);

    Contact::factory()->withCustomFields([$field->key => 'EMP001'])->create([
        'organization_id' => $this->org->id,
    ]);

    $result = $this->service->processRow([
        'name' => 'Dup',
        'email' => 'dup@example.com',
        'tags' => '',
        'Employee ID' => 'EMP001',
    ], [$field->name => $field]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Employee ID');
});

test('select value not in allowed options returns error', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Status',
        'type' => 'select',
        'unique' => false,
        'options' => [
            ['label' => 'Active', 'value' => 'active'],
            ['label' => 'Inactive', 'value' => 'inactive'],
        ],
        'order' => 1,
    ]);

    $result = $this->service->processRow([
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => '',
        'Status' => 'unknown_value',
    ], [$field->name => $field]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Status');
});

test('valid select value is stored', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Status',
        'type' => 'select',
        'unique' => false,
        'options' => [
            ['label' => 'Active', 'value' => 'active'],
        ],
        'order' => 1,
    ]);

    $this->service->processRow([
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => '',
        'Status' => 'active',
    ], [$field->name => $field]);

    $contact = Contact::where('email', 'test@example.com')->first();
    expect($contact->custom_field_values[$field->key])->toBe('active');
});

// parseMultiselectValue — semicolon-separated, validates options
test('parseMultiselectValue splits by semicolon and validates options', function () {
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

    $result = $this->service->parseMultiselectValue($field, 'sports;tech');

    expect($result)->toBe(['sports', 'tech']);
});

test('parseMultiselectValue returns null when value not in allowed options', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Interests',
        'type' => 'multiselect',
        'unique' => false,
        'options' => [
            ['label' => 'Sports', 'value' => 'sports'],
        ],
        'order' => 1,
    ]);

    $result = $this->service->parseMultiselectValue($field, 'sports;invalid');

    expect($result)->toBeNull();
});

test('multiselect values are stored as array', function () {
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

    $this->service->processRow([
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => '',
        'Interests' => 'sports;tech',
    ], [$field->name => $field]);

    $contact = Contact::where('email', 'test@example.com')->first();
    expect($contact->custom_field_values[$field->key])->toBe(['sports', 'tech']);
});

test('multiselect with invalid option returns error', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Interests',
        'type' => 'multiselect',
        'unique' => false,
        'options' => [
            ['label' => 'Sports', 'value' => 'sports'],
        ],
        'order' => 1,
    ]);

    $result = $this->service->processRow([
        'name' => 'Test',
        'email' => 'test@example.com',
        'tags' => '',
        'Interests' => 'sports;invalid',
    ], [$field->name => $field]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Interests');
});

// resolveTagIds
test('resolveTagIds returns empty array for blank input', function () {
    expect($this->service->resolveTagIds(''))->toBe([]);
});

test('resolveTagIds splits by semicolon and creates tags', function () {
    $ids = $this->service->resolveTagIds('Alpha;Beta');

    expect(count($ids))->toBe(2);
    expect(Tag::whereIn('id', $ids)->where('organization_id', $this->org->id)->count())->toBe(2);
});

// parseDate
test('parseDate accepts d-m-Y format', function () {
    $result = $this->service->parseDate('15-01-2024');

    expect($result)->not->toBeFalse();
});

test('parseDate rejects invalid format', function () {
    $result = $this->service->parseDate('2024-01-15');

    expect($result)->toBeFalse();
});

test('row matching a soft-deleted contact is reported instead of crashing', function () {
    $trashed = Contact::factory()->create(['organization_id' => $this->org->id, 'email' => 'gone@example.com']);
    $trashed->delete();

    $result = $this->service->processRow([
        'name' => 'Gone Again',
        'email' => 'gone@example.com',
        'phone' => '',
        'tags' => '',
    ], []);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Restore it from the trash');
    expect(Contact::withTrashed()->where('email', 'gone@example.com')->count())->toBe(1);
});
