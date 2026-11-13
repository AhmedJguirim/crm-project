<?php

use App\Models\User;

test('a signed-in user opening a dead Filament download url gets not found', function (string $url) {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->actingAs($user)->get($url)->assertNotFound();
})->with([
    '/filament/exports/1/download',
    '/filament/imports/1/failed-rows/download',
    '/filament/exports/abc/download',
    '/filament/imports/99999999999999999999/failed-rows/download',
]);

test('a guest opening a dead Filament download url gets not found', function (string $url) {
    $this->get($url)->assertNotFound();
})->with([
    '/filament/exports/1/download',
    '/filament/imports/1/failed-rows/download',
]);

test('the dead Filament download routes keep their names', function () {
    expect(route('filament.exports.download', 1, false))->toBe('/filament/exports/1/download')
        ->and(route('filament.imports.failed-rows.download', 1, false))->toBe('/filament/imports/1/failed-rows/download');
});
