<?php

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('profile page links back to the panel and no longer shows the starter-kit pages', function () {
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    $panelUrl = filament()->getUrl();

    expect($panelUrl)->toEndWith('/admin/acme');

    $response = $this->get(route('profile.edit'))->assertOk();

    expect(substr_count($response->getContent(), 'href="'.$panelUrl.'"'))->toBeGreaterThanOrEqual(2);

    $response
        ->assertDontSee('/settings/appearance', false)
        ->assertDontSee('livewire-starter-kit', false);
});

test('the settings sidebar shows the panel brand, not the starter-kit name', function () {
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('>CRM</div>', false)
        ->assertDontSee('Laravel Starter Kit', false)
        ->assertDontSee('Starter Kit', false);
});

test('the settings sidebar brand follows the panel brand name', function () {
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    filament()->getDefaultPanel()->brandName('Acme Sales');

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('>Acme Sales</div>', false)
        ->assertDontSee('>CRM</div>', false);
});

test('the settings sidebar prints a hostile panel brand name escaped', function () {
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    filament()->getDefaultPanel()->brandName('<script>alert(1)</script>');

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

test('an already verified user resending the verification mail is sent to the panel without a mail', function () {
    Notification::fake();
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create();
    $organization->members()->attach($user, ['role' => OrganizationRole::Member->value]);

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->call('resendVerificationNotification')
        ->assertRedirect(filament()->getUrl());

    Notification::assertNothingSent();
});
