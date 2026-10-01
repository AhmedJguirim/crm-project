<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('list page loads successfully', function () {
    Livewire::test(ListTags::class)
        ->assertSuccessful();
});

test('list page displays tags for the organization', function () {
    $tags = Tag::factory()->count(3)->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords($tags)
        ->assertCountTableRecords(3);
});

test('table displays name and color columns', function () {
    Tag::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ListTags::class)
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('color');
});

// Empty state
test('empty state is shown when no tags exist', function () {
    Livewire::test(ListTags::class)
        ->assertCountTableRecords(0);
});

test('create action exists in header', function () {
    Livewire::test(ListTags::class)
        ->assertActionExists('create');
});

// Search
test('table can be searched by name', function () {
    $matching = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'vip',
    ]);

    $other = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'prospect',
    ]);

    Livewire::test(ListTags::class)
        ->searchTable('vip')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

test('search with no matches shows no results', function () {
    Tag::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ListTags::class)
        ->searchTable('zzznomatch')
        ->assertCountTableRecords(0);
});

// Sorting
test('table can be sorted by name', function () {
    Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'zebra']);
    Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'alpha']);

    $sorted = Tag::where('organization_id', $this->org->id)->orderBy('name')->get();

    Livewire::test(ListTags::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($sorted, inOrder: true);
});

// Organization isolation
test('user only sees tags from their organization', function () {
    $ownTag = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'mine',
    ]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $otherTag = Tag::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'theirs',
    ]);

    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords([$ownTag])
        ->assertCanNotSeeTableRecords([$otherTag]);
});

test('switching organizations shows different tags', function () {
    $tag1 = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'org-one-tag',
    ]);

    $secondOrg = Organization::factory()->create(['created_by' => $this->user->id]);
    $this->user->organizations()->attach($secondOrg, ['role' => OrganizationRole::Owner->value]);

    $tag2 = Tag::factory()->create([
        'organization_id' => $secondOrg->id,
        'name' => 'org-two-tag',
    ]);

    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords([$tag1])
        ->assertCanNotSeeTableRecords([$tag2]);

    Filament::setTenant($secondOrg);

    Livewire::test(ListTags::class)
        ->assertCanSeeTableRecords([$tag2])
        ->assertCanNotSeeTableRecords([$tag1]);
});

// Bulk delete
test('user can bulk delete tags', function () {
    $tags = Tag::factory()->count(3)->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ListTags::class)
        ->selectTableRecords($tags)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanNotSeeTableRecords($tags);

    expect(Tag::where('organization_id', $this->org->id)->count())->toBe(0);
    $tags->each(fn (Tag $tag) => $this->assertSoftDeleted($tag));
});

test('bulk delete only removes selected tags', function () {
    $toDelete = Tag::factory()->count(2)->create([
        'organization_id' => $this->org->id,
    ]);

    $toKeep = Tag::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'keepme',
    ]);

    Livewire::test(ListTags::class)
        ->selectTableRecords($toDelete)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanSeeTableRecords([$toKeep]);

    expect(Tag::find($toKeep->id))->not->toBeNull();
});
