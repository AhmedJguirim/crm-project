<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

test('the removed inertia welcome page answers 404 for a signed-in member', function () {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user)->get('/app')->assertNotFound();
});
