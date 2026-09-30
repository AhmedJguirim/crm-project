<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

function memberOf(Organization $organization): User
{
    $user = User::factory()->create();

    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    return $user;
}

test('guests are redirected to the login page', function () {
    $organization = Organization::factory()->create();

    $this->get(route('app.tags.index', $organization))
        ->assertRedirect(route('login'));
});

test('members only see the tags of the current organization', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    Tag::factory()->for($organization)->create(['name' => 'Alpha']);
    Tag::factory()->for($organization)->create(['name' => 'Beta']);
    Tag::factory()->for($otherOrganization)->create(['name' => 'Foreign']);

    $this->actingAs(memberOf($organization))
        ->get(route('app.tags.index', $organization))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tags/Index')
            ->has('tags', 2)
            ->where('tags.0.name', 'Alpha')
            ->where('tags.1.name', 'Beta')
            ->where('currentOrganization.slug', $organization->slug)
            ->has('organizations', 1)
        );
});

test('users cannot view tags of an organization they do not belong to', function () {
    $organization = Organization::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $this->actingAs(memberOf($organization))
        ->get(route('app.tags.index', $otherOrganization))
        ->assertForbidden();
});

test('members can view a single tag without the organization slug in the url', function () {
    $organization = Organization::factory()->create();
    $tag = Tag::factory()->for($organization)->create(['name' => 'Hot lead']);

    $this->actingAs(memberOf($organization))
        ->get(route('app.tags.show', $tag))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Tags/Show')
            ->where('tag.id', $tag->id)
            ->where('tag.name', 'Hot lead')
            ->where('currentOrganization.slug', $organization->slug)
        );
});

test('a tag from another organization returns not found', function () {
    $organization = Organization::factory()->create();
    $foreignTag = Tag::factory()->for(Organization::factory())->create();

    $this->actingAs(memberOf($organization))
        ->get(route('app.tags.show', $foreignTag))
        ->assertNotFound();
});

test('guests are redirected to login when viewing a single tag', function () {
    $tag = Tag::factory()->create();

    $this->get(route('app.tags.show', $tag))
        ->assertRedirect(route('login'));
});
