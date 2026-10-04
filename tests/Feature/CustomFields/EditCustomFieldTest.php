<?php

use App\Filament\Resources\CustomFields\Pages\EditCustomField;
use App\Models\CustomField;
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

// TSK-2026-0008 AC-001: Form pre-populated with existing data
test('edit form is pre-populated with existing field data', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Website',
        'type' => 'url',
        'unique' => true,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->assertFormSet([
            'name' => 'Website',
            'type' => 'url',
            'unique' => true,
        ]);
});

test('edit form shows options for select type', function () {
    $field = CustomField::factory()->select()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->assertFormSet([
            'type' => 'select',
        ])
        ->assertFormFieldExists('options');
});

// TSK-2026-0008 AC-002: Successful update
test('user can update custom field name', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Old Name',
        'type' => 'text',
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $field->refresh();
    expect($field->name)->toBe('New Name');
});

test('user can update unique toggle', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'unique' => false,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm(['unique' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    $field->refresh();
    expect($field->unique)->toBeTrue();
});

test('user can update select options', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'type' => 'select',
        'options' => [
            ['label' => 'Original 1', 'value' => 'orig1'],
        ],
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm([
            'options' => [
                ['label' => 'Updated 1', 'value' => 'upd1'],
                ['label' => 'Updated 2', 'value' => 'upd2'],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $field->refresh();
    expect($field->options)->toBeArray()
        ->and(count($field->options))->toBeGreaterThanOrEqual(2);
});

// TSK-2026-0008 AC-003: Validation on update
test('update requires name', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => ''])
        ->call('save')
        ->assertHasFormErrors(['name' => 'required']);
});

test('update rejects duplicate name in same organization', function () {
    $field1 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Existing Field',
    ]);

    $field2 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Field to Edit',
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field2->id])
        ->fillForm(['name' => 'Existing Field'])
        ->call('save')
        ->assertHasFormErrors(['name']);
});

test('update allows keeping same name', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'My Field',
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => 'My Field'])
        ->call('save')
        ->assertHasNoFormErrors();
});

// TSK-2026-0008 AC-004: Position handling on edit
test('field position can be changed to beginning', function () {
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

    Livewire::test(EditCustomField::class, ['record' => $field2->id])
        ->fillForm(['position' => 'beginning'])
        ->call('save')
        ->assertHasNoFormErrors();

    $allFields = CustomField::where('organization_id', $this->org->id)
        ->orderBy('order')
        ->pluck('name')
        ->toArray();

    expect($allFields[0])->toBe('Field 2');
});

test('field position can be changed to after another field', function () {
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

    $field3 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Field 3',
        'order' => 3,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field3->id])
        ->fillForm(['position' => "after_{$field1->id}"])
        ->call('save')
        ->assertHasNoFormErrors();

    $allFields = CustomField::where('organization_id', $this->org->id)
        ->orderBy('order')
        ->pluck('name')
        ->toArray();

    expect($allFields)->toBe(['Field 1', 'Field 3', 'Field 2']);
});

// TSK-2026-0008 AC-005: The type is locked once the field exists (BUG-14)
test('the type is disabled and a forged type is ignored when the field is saved', function () {
    $field = CustomField::factory()->text()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->assertFormFieldDisabled('type')
        ->set('data.type', 'number')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($field->fresh()->type)->toBe('text');
});

test('a select field keeps its type and its options when saved', function () {
    $field = CustomField::factory()->select()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->assertFormFieldDisabled('type')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($field->fresh()->type)->toBe('select')
        ->and($field->fresh()->options)->toBe($field->options);
});

// Edge cases
test('name can be updated to 255 characters', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    $longName = str_repeat('a', 255);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => $longName])
        ->call('save')
        ->assertHasNoFormErrors();

    $field->refresh();
    expect($field->name)->toBe($longName);
});

test('name cannot exceed 255 characters', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => str_repeat('a', 256)])
        ->call('save')
        ->assertHasFormErrors(['name' => 'max']);
});

// Delete action
test('delete action exists on edit page', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->assertActionExists(DeleteAction::class);
});

test('user can delete custom field from edit page', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    expect(CustomField::find($field->id))->toBeNull();
});

// Organization isolation
test('same field name is allowed in a different organization', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    CustomField::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'Shared Name',
    ]);

    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'My Field',
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field->id])
        ->fillForm(['name' => 'Shared Name'])
        ->call('save')
        ->assertHasNoFormErrors();
});

// Position 'end' skips reorder logic
test('saving with end position does not change field ordering', function () {
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

    Livewire::test(EditCustomField::class, ['record' => $field1->id])
        ->fillForm([
            'name' => 'Field 1 Updated',
            'position' => 'end',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $orderedNames = CustomField::where('organization_id', $this->org->id)
        ->orderBy('order')
        ->pluck('name')
        ->toArray();

    expect($orderedNames)->toBe(['Field 1 Updated', 'Field 2']);
});

// afterSave resequencing
test('afterSave resequences gapped orders to be sequential', function () {
    $field1 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Field 1',
        'order' => 1,
    ]);

    CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Field 2',
        'order' => 5,
    ]);

    CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Field 3',
        'order' => 10,
    ]);

    Livewire::test(EditCustomField::class, ['record' => $field1->id])
        ->fillForm(['name' => 'Field 1'])
        ->call('save')
        ->assertHasNoFormErrors();

    $orders = CustomField::where('organization_id', $this->org->id)
        ->orderBy('order')
        ->pluck('order')
        ->toArray();

    expect($orders)->toBe([1, 2, 3]);
});
