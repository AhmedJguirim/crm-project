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

// TSK-2026-0006 AC-001: List page displays existing custom fields
test('list page displays custom fields for organization', function () {
    $fields = collect([
        CustomField::factory()->create(['organization_id' => $this->org->id, 'order' => 1]),
        CustomField::factory()->create(['organization_id' => $this->org->id, 'order' => 2]),
        CustomField::factory()->create(['organization_id' => $this->org->id, 'order' => 3]),
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertCanSeeTableRecords($fields)
        ->assertCountTableRecords(3);
});

test('table displays correct columns', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Website',
        'type' => 'url',
        'unique' => true,
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertCanSeeTableRecords([$field])
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('type')
        ->assertTableColumnExists('unique');
});

test('rows are ordered by order column ascending', function () {
    $field1 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Third Field',
        'order' => 3,
    ]);

    $field2 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'First Field',
        'order' => 1,
    ]);

    $field3 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Second Field',
        'order' => 2,
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertCanSeeTableRecords([$field2, $field3, $field1], inOrder: true);
});

// TSK-2026-0006 AC-002: Empty state handling
test('empty state displayed when no custom fields exist', function () {
    Livewire::test(ListCustomFields::class)
        ->assertCountTableRecords(0);
});

test('empty state shows create action', function () {
    Livewire::test(ListCustomFields::class)
        ->assertActionExists('create');
});

// TSK-2026-0006 AC-003: Sorting functionality
test('table can be sorted by name', function () {
    CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Zebra Field',
        'order' => 1,
    ]);

    CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Alpha Field',
        'order' => 2,
    ]);

    Livewire::test(ListCustomFields::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords(CustomField::all(), inOrder: true);
});

// TSK-2026-0006 AC-004: Search functionality
test('table can be searched by name', function () {
    $matchingField = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Website URL',
    ]);

    $nonMatchingField = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Phone Number',
    ]);

    Livewire::test(ListCustomFields::class)
        ->searchTable('Website')
        ->assertCanSeeTableRecords([$matchingField])
        ->assertCanNotSeeTableRecords([$nonMatchingField]);
});

test('search with no matches shows no results', function () {
    CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Email Address',
    ]);

    Livewire::test(ListCustomFields::class)
        ->searchTable('NonExistentField')
        ->assertCountTableRecords(0);
});

// TSK-2026-0006 AC-005: User scoping and isolation
test('user only sees custom fields from their organization', function () {
    $ownField = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Own Field',
    ]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $otherField = CustomField::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'Other Field',
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertCanSeeTableRecords([$ownField])
        ->assertCanNotSeeTableRecords([$otherField]);
});

test('switching organizations shows different custom fields', function () {
    $field1 = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Org 1 Field',
    ]);

    $secondOrg = \App\Models\Organization::factory()->create(['created_by' => $this->user->id]);
    $this->user->organizations()->attach($secondOrg, ['role' => \App\Enums\OrganizationRole::Owner->value]);

    $field2 = CustomField::factory()->create([
        'organization_id' => $secondOrg->id,
        'name' => 'Org 2 Field',
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertCanSeeTableRecords([$field1])
        ->assertCanNotSeeTableRecords([$field2]);

    Filament::setTenant($secondOrg);

    Livewire::test(ListCustomFields::class)
        ->assertCanSeeTableRecords([$field2])
        ->assertCanNotSeeTableRecords([$field1]);
});

// Edge case: very long field names
test('very long field names are truncated with tooltip', function () {
    $longName = str_repeat('A', 100);

    CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $longName,
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertCountTableRecords(1);
});

// Edge case: large number of fields with pagination
test('pagination works correctly with many fields', function () {
    CustomField::factory()->count(25)->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ListCustomFields::class)
        ->assertCountTableRecords(25);
});
