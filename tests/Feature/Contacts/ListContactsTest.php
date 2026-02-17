<?php

use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Models\Contact;
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
    Livewire::test(ListContacts::class)
        ->assertSuccessful();
});

test('list page displays contacts for the organization', function () {
    $contacts = Contact::factory()->count(3)->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ListContacts::class)
        ->assertCanSeeTableRecords($contacts)
        ->assertCountTableRecords(3);
});

test('table displays required columns', function () {
    Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ListContacts::class)
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('email')
        ->assertTableColumnExists('created_at');
});

test('empty state is shown when no contacts exist', function () {
    Livewire::test(ListContacts::class)
        ->assertCountTableRecords(0);
});

test('header actions exist: create, importCsv, downloadTemplate', function () {
    Livewire::test(ListContacts::class)
        ->assertActionExists('create')
        ->assertActionExists('importCsv')
        ->assertActionExists('downloadTemplate');
});

// Search
test('table can be searched by name', function () {
    $matching = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Alice Smith',
    ]);

    $other = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Bob Jones',
    ]);

    Livewire::test(ListContacts::class)
        ->searchTable('Alice')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

test('table can be searched by email', function () {
    $matching = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'unique@example.com',
    ]);

    $other = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'other@test.com',
    ]);

    Livewire::test(ListContacts::class)
        ->searchTable('unique@example.com')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

// Sorting
test('table can be sorted by name ascending', function () {
    Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Zara']);
    Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Aaron']);

    $sorted = Contact::where('organization_id', $this->org->id)->orderBy('name')->get();

    Livewire::test(ListContacts::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($sorted, inOrder: true);
});

test('table can be sorted by email', function () {
    Contact::factory()->create(['organization_id' => $this->org->id, 'email' => 'z@example.com', 'name' => 'Z']);
    Contact::factory()->create(['organization_id' => $this->org->id, 'email' => 'a@example.com', 'name' => 'A']);

    $sorted = Contact::where('organization_id', $this->org->id)->orderBy('email')->get();

    Livewire::test(ListContacts::class)
        ->sortTable('email')
        ->assertCanSeeTableRecords($sorted, inOrder: true);
});

// Tag filter
test('table can be filtered by tag', function () {
    $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);

    $tagged = Contact::factory()->create(['organization_id' => $this->org->id]);
    $tagged->tags()->sync([$tag->id]);

    $untagged = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ListContacts::class)
        ->filterTable('tags', [$tag->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$untagged]);
});

// Organization isolation
test('user only sees contacts from their organization', function () {
    $ownContact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'My Contact',
    ]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $otherContact = Contact::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'Other Contact',
    ]);

    Livewire::test(ListContacts::class)
        ->assertCanSeeTableRecords([$ownContact])
        ->assertCanNotSeeTableRecords([$otherContact]);
});

// Bulk delete
test('user can bulk delete contacts', function () {
    $contacts = Contact::factory()->count(3)->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ListContacts::class)
        ->selectTableRecords($contacts)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanNotSeeTableRecords($contacts);

    expect(Contact::where('organization_id', $this->org->id)->count())->toBe(0);
});

test('bulk delete only removes selected contacts', function () {
    $toDelete = Contact::factory()->count(2)->create([
        'organization_id' => $this->org->id,
    ]);

    $toKeep = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'keepme@example.com',
    ]);

    Livewire::test(ListContacts::class)
        ->selectTableRecords($toDelete)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanSeeTableRecords([$toKeep]);

    expect(Contact::find($toKeep->id))->not->toBeNull();
});
