<?php

use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
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

// TSK-2026-0010: Filament native reordering tests
test('list page loads successfully with multiple fields', function () {
    CustomField::factory()->count(3)->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertSuccessful();
});

test('fields are displayed in correct order', function () {
    $field1 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'First',
        'order' => 1,
    ]);

    $field2 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Second',
        'order' => 2,
    ]);

    $field3 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Third',
        'order' => 3,
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertCanSeeTableRecords([$field1, $field2, $field3], inOrder: true);
});

test('order column is fillable for reordering', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'order' => 1,
    ]);

    $field->update(['order' => 5]);
    $field->refresh();

    expect($field->order)->toBe(5);
});

test('manual order update works correctly', function () {
    $field1 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'First',
        'order' => 1,
    ]);

    $field2 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Second',
        'order' => 2,
    ]);

    // Manually swap orders
    $field1->update(['order' => 2]);
    $field2->update(['order' => 1]);

    $orderedFields = CustomField::where('organization_id', $this->org->id)
        ->orderBy('order')
        ->pluck('name')
        ->toArray();

    expect($orderedFields)->toBe(['Second', 'First']);
});

test('list page handles many fields', function () {
    CustomField::factory()->count(50)->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertSuccessful();

    $count = CustomField::where('organization_id', $this->org->id)->count();
    expect($count)->toBe(50);
});

test('only current organization fields are shown', function () {
    $ownField1 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Own 1',
        'order' => 1,
    ]);

    $ownField2 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Own 2',
        'order' => 2,
    ]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $otherField = CustomField::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'Other',
        'order' => 1,
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertCanSeeTableRecords([$ownField1, $ownField2])
        ->assertCanNotSeeTableRecords([$otherField]);
});

test('order values remain sequential after updates', function () {
    $fields = CustomField::factory()->count(5)->create([
        'organization_id' => $this->org->id,
    ]);

    // Manually set sequential orders
    $fields->each(fn ($field, $index) => $field->update(['order' => $index + 1]));

    $orders = CustomField::where('organization_id', $this->org->id)
        ->orderBy('order')
        ->pluck('order')
        ->toArray();

    expect($orders)->toBe([1, 2, 3, 4, 5]);
});

test('observer assigns next available order value', function () {
    // Create fields with explicit order values
    CustomField::create([
        'organization_id' => $this->org->id,
        'name' => 'Field 1',
        'type' => 'text',
        'order' => 1,
    ]);

    CustomField::create([
        'organization_id' => $this->org->id,
        'name' => 'Field 2',
        'type' => 'text',
        'order' => 2,
    ]);

    CustomField::create([
        'organization_id' => $this->org->id,
        'name' => 'Field 3',
        'type' => 'text',
        'order' => 3,
    ]);

    // Create new field without specifying order - observer should assign 4
    $newField = CustomField::create([
        'organization_id' => $this->org->id,
        'name' => 'Field 4',
        'type' => 'text',
    ]);

    expect($newField->order)->toBe(4);
});
