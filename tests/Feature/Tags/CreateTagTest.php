<?php

use App\Filament\Resources\Tags\Pages\CreateTag;
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
test('user can create a tag with a name only', function () {
    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'VIP',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tag = Tag::where('name', 'VIP')->first();

    expect($tag)->not->toBeNull()
        ->and($tag->organization_id)->toBe($this->org->id)
        ->and($tag->color)->toBeNull();
});

test('user can create a tag with a name and color', function () {
    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Hot Lead',
            'color' => '#ff0000',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $tag = Tag::where('name', 'Hot Lead')->first();

    expect($tag)->not->toBeNull()
        ->and($tag->color)->toBe('#ff0000');
});

test('color is optional on creation', function () {
    Livewire::test(CreateTag::class)
        ->fillForm([
            'name' => 'Newsletter',
            'color' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tag::where('name', 'Newsletter')->first()->color)->toBeNull();
});

test('created tag is scoped to the current organization', function () {
    Livewire::test(CreateTag::class)
        ->fillForm(['name' => 'Scoped Tag'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tag::where('name', 'Scoped Tag')->first()->organization_id)->toBe($this->org->id);
});

// Validation
test('creation requires name', function () {
    Livewire::test(CreateTag::class)
        ->fillForm(['name' => ''])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);

    expect(Tag::count())->toBe(0);
});

test('name cannot exceed 255 characters', function () {
    Livewire::test(CreateTag::class)
        ->fillForm(['name' => str_repeat('a', 256)])
        ->call('create')
        ->assertHasFormErrors(['name' => 'max']);
});

test('name of exactly 255 characters is accepted', function () {
    $name = str_repeat('a', 255);

    Livewire::test(CreateTag::class)
        ->fillForm(['name' => $name])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tag::where('name', $name)->exists())->toBeTrue();
});

test('creation rejects duplicate name in the same organization', function () {
    Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Existing Tag',
    ]);

    Livewire::test(CreateTag::class)
        ->fillForm(['name' => 'Existing Tag'])
        ->call('create')
        ->assertHasFormErrors(['name']);

    expect(Tag::where('name', 'Existing Tag')->count())->toBe(1);
});

// Organization-scoped uniqueness
test('same tag name is allowed in a different organization', function () {
    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    Tag::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'Shared Name',
    ]);

    Livewire::test(CreateTag::class)
        ->fillForm(['name' => 'Shared Name'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Tag::where('name', 'Shared Name')->count())->toBe(2);
});
