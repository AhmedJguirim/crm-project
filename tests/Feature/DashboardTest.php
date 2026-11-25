<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;

dataset('removedStarterKitPaths', ['/dashboard', '/app']);

test('a guest gets a 404 on a removed starter-kit page', function (string $path) {
    $this->get($path)->assertNotFound();
})->with('removedStarterKitPaths');

test('a signed-in verified member gets a 404 on a removed starter-kit page', function (string $path) {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user)->get($path)->assertNotFound();
})->with('removedStarterKitPaths');

test('the removed starter-kit route names no longer exist', function () {
    expect(Route::has('dashboard'))->toBeFalse()
        ->and(Route::has('app.welcome'))->toBeFalse()
        ->and(Route::has('appearance.edit'))->toBeFalse();
});
