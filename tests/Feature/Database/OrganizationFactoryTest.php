<?php

use App\Models\Organization;
use App\Models\User;

it('gives two organizations with the same name different slugs', function () {
    $user = User::factory()->create();

    $first = Organization::factory()->create(['name' => 'Acme Inc', 'created_by' => $user->id]);
    $second = Organization::factory()->create(['name' => 'Acme Inc', 'created_by' => $user->id]);

    expect($first->slug)->toMatch('/^acme-inc-[a-z0-9]{6}\z/')
        ->and($second->slug)->toMatch('/^acme-inc-[a-z0-9]{6}\z/')
        ->and($first->slug)->not->toBe($second->slug);
});

it('derives the slug from an overridden name', function () {
    $organization = Organization::factory()->create(['name' => 'Globex Corporation']);

    expect($organization->slug)->toStartWith('globex-corporation-');
});

it('keeps an explicit slug', function () {
    $organization = Organization::factory()->create(['slug' => 'fixed-slug']);

    expect($organization->slug)->toBe('fixed-slug');
});

it('never produces the same slug twice across many default organizations', function () {
    $user = User::factory()->create();

    Organization::factory()->count(200)->create(['created_by' => $user->id]);

    expect(Organization::query()->withoutGlobalScopes()->distinct()->count('slug'))->toBe(200);
});
