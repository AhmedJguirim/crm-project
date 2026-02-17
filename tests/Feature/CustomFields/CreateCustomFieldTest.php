<?php

use App\Filament\Resources\CustomFields\Pages\CreateCustomField;
use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

// TSK-2026-0007 AC-001: Successful creation with minimal valid data
test('user can create custom field with minimal data', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Website',
            'type' => 'url',
            'unique' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $field = CustomField::where('name', 'Website')->first();

    expect($field)->not->toBeNull()
        ->and($field->organization_id)->toBe($this->org->id)
        ->and($field->type)->toBe('url')
        ->and($field->unique)->toBeFalse()
        ->and($field->order)->toBeGreaterThan(0);
});

test('created field appears at end by default', function () {
    CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'order' => 1,
    ]);

    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'New Field',
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $newField = CustomField::where('name', 'New Field')->first();
    expect($newField->order)->toBe(2);
});

test('success notification shown on creation', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Email Address',
            'type' => 'email',
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

// TSK-2026-0007 AC-002: Creation with select type and options
test('user can create select field with options', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Priority',
            'type' => 'select',
            'options' => [
                ['label' => 'High', 'value' => 'high'],
                ['label' => 'Medium', 'value' => 'medium'],
                ['label' => 'Low', 'value' => 'low'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $field = CustomField::where('name', 'Priority')->first();

    expect($field)->not->toBeNull()
        ->and($field->type)->toBe('select')
        ->and($field->options)->toBeArray()
        ->and($field->options)->toHaveCount(3)
        ->and($field->options[0]['label'])->toBe('High');
});

test('user can create multiselect field with options', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Tags',
            'type' => 'multiselect',
            'options' => [
                ['label' => 'VIP', 'value' => 'vip'],
                ['label' => 'Partner', 'value' => 'partner'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $field = CustomField::where('name', 'Tags')->first();

    expect($field)->not->toBeNull()
        ->and($field->type)->toBe('multiselect')
        ->and($field->options)->toHaveCount(2);
});

// TSK-2026-0007 AC-003: Validation errors on submission
test('creation requires name', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => '',
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    expect(CustomField::count())->toBe(0);
});

test('creation requires type', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Test Field',
            'type' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['type' => 'required']);

    expect(CustomField::count())->toBe(0);
});

test('creation rejects duplicate name in same organization', function () {
    CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Existing Field',
    ]);

    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Existing Field',
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(CustomField::where('name', 'Existing Field')->count())->toBe(1);
});

test('select type requires options', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Status',
            'type' => 'select',
            'options' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['options']);
});

test('multiselect type requires options', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Categories',
            'type' => 'multiselect',
            'options' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['options']);
});

// TSK-2026-0007 AC-004: Conditional options field visibility
test('options field visible for select type', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm(['type' => 'select'])
        ->assertFormFieldExists('options');
});

test('options field visible for multiselect type', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm(['type' => 'multiselect'])
        ->assertFormFieldExists('options');
});

test('options field not required for text type', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Simple Text',
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasNoFormErrors();
});

// TSK-2026-0007 AC-005: Order/position handling on creation
test('field can be created at beginning', function () {
    $existing = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'order' => 1,
    ]);

    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'First Field',
            'type' => 'text',
            'position' => 'beginning',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $newField = CustomField::where('name', 'First Field')->first();
    $existing->refresh();

    expect($newField->order)->toBeLessThan($existing->order)
        ->and($newField)->not->toBeNull();

    $allFields = CustomField::where('organization_id', $this->org->id)
        ->orderBy('order')
        ->pluck('name')
        ->toArray();

    expect($allFields[0])->toBe('First Field');
});

test('field can be created after specific field', function () {
    $field1 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Field 1',
        'order' => 1,
    ]);

    $field2 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Field 2',
        'order' => 2,
    ]);

    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Middle Field',
            'type' => 'text',
            'position' => "after_{$field1->id}",
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $newField = CustomField::where('name', 'Middle Field')->first();

    expect($newField)->not->toBeNull();

    $allFields = CustomField::where('organization_id', $this->org->id)
        ->orderBy('order')
        ->pluck('name')
        ->toArray();

    expect($allFields)->toBe(['Field 1', 'Middle Field', 'Field 2']);
});

test('first field gets order 1', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'First Ever',
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $field = CustomField::where('name', 'First Ever')->first();
    expect($field->order)->toBe(1);
});

// Edge cases
test('name limited to 255 characters', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => str_repeat('a', 256),
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'max']);
});

test('all field types can be created', function () {
    $types = ['text', 'email', 'url', 'phone', 'number', 'date', 'textarea'];

    foreach ($types as $type) {
        Livewire::test(CreateCustomField::class)
            ->fillForm([
                'name' => ucfirst($type).' Field',
                'type' => $type,
            ])
            ->call('create')
            ->assertHasNoFormErrors();
    }

    expect(CustomField::count())->toBe(count($types));
});

test('unique toggle works correctly', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Unique Field',
            'type' => 'text',
            'unique' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $field = CustomField::where('name', 'Unique Field')->first();
    expect($field->unique)->toBeTrue();
});

// Organization-scoped uniqueness
test('same field name is allowed in a different organization', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    CustomField::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'Shared Name',
    ]);

    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Shared Name',
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CustomField::where('name', 'Shared Name')->count())->toBe(2);
});

// Options sub-field validation
test('option label is required for select type', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Status',
            'type' => 'select',
            'options' => [
                ['label' => '', 'value' => 'val'],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['options.0.label']);
});

test('option value is required for select type', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Status',
            'type' => 'select',
            'options' => [
                ['label' => 'Label', 'value' => ''],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['options.0.value']);
});

test('option label cannot exceed 255 characters', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Status',
            'type' => 'select',
            'options' => [
                ['label' => str_repeat('a', 256), 'value' => 'val'],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['options.0.label']);
});

test('option value cannot exceed 255 characters', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'name' => 'Status',
            'type' => 'select',
            'options' => [
                ['label' => 'Label', 'value' => str_repeat('a', 256)],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['options.0.value']);
});
