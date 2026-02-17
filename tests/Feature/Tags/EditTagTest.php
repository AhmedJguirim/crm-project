<?php

use App\Filament\Resources\Tags\Pages\EditTag;
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
test('edit form is pre-populated with existing tag data', function () {
    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Newsletter',
        'color' => '#3b82f6',
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->assertFormSet([
            'name' => 'Newsletter',
            'color' => '#3b82f6',
        ]);
});

test('edit form shows null color when tag has no color', function () {
    $tag = Tag::factory()->withoutColor()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->assertFormSet([
            'color' => null,
        ]);
});

// Successful updates
test('user can update a tag name', function () {
    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Old Name',
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->name)->toBe('New Name');
});

test('user can update a tag color', function () {
    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'color' => '#ff0000',
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->fillForm(['color' => '#00ff00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->color)->toBe('#00ff00');
});

test('user can clear a tag color', function () {
    $tag = Tag::factory()->withColor()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->fillForm(['color' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($tag->fresh()->color)->toBeNull();
});

// Validation on update
test('update requires name', function () {
    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->fillForm(['name' => ''])
        ->call('save')
        ->assertHasFormErrors(['name' => 'required']);
});

test('name cannot exceed 255 characters on update', function () {
    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->fillForm(['name' => str_repeat('a', 256)])
        ->call('save')
        ->assertHasFormErrors(['name' => 'max']);
});

test('update rejects duplicate name in the same organization', function () {
    Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Existing Tag',
    ]);

    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'My Tag',
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->fillForm(['name' => 'Existing Tag'])
        ->call('save')
        ->assertHasFormErrors(['name']);
});

test('update allows keeping the same name', function () {
    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'My Tag',
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->fillForm(['name' => 'My Tag'])
        ->call('save')
        ->assertHasNoFormErrors();
});

// Organization-scoped uniqueness
test('same tag name is allowed in a different organization', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    Tag::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'Cross Org Name',
    ]);

    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'My Tag',
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->fillForm(['name' => 'Cross Org Name'])
        ->call('save')
        ->assertHasNoFormErrors();
});

// Delete action
test('delete action exists on edit page', function () {
    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->assertActionExists(DeleteAction::class);
});

test('user can delete a tag from the edit page', function () {
    $tag = Tag::factory()->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(EditTag::class, ['record' => $tag->id])
        ->callAction(DeleteAction::class)
        ->assertNotified()
        ->assertRedirect();

    expect(Tag::find($tag->id))->toBeNull();
});
