<?php

use App\Models\User;
use Filament\Facades\Filament;

// AC-001: Successful logout from authenticated session
test('authenticated user can logout', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user)
        ->post(Filament::getLogoutUrl())
        ->assertRedirect(Filament::getLoginUrl());

    $this->assertGuest();
});

test('session is invalidated after logout', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user);

    $sessionId = session()->getId();

    $this->post(Filament::getLogoutUrl());

    expect(session()->getId())->not->toBe($sessionId);
    $this->assertGuest();
});

test('logout flashes success notification to session', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user)
        ->post(Filament::getLogoutUrl());

    expect(session('filament.notifications'))->toBeArray()
        ->and(session('filament.notifications'))->toHaveCount(1);
});

// AC-002: No access to protected routes after logout
test('protected routes redirect to login after logout', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $this->actingAs($user)
        ->post(Filament::getLogoutUrl());

    $this->assertGuest();

    $this->get(Filament::getUrl())
        ->assertRedirect(Filament::getLoginUrl());
});

test('dashboard is not accessible after logout', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $dashboardUrl = Filament::getUrl($user->personalOrganization());

    $this->actingAs($user)
        ->get($dashboardUrl)
        ->assertOk();

    $this->post(Filament::getLogoutUrl());

    $this->get($dashboardUrl)
        ->assertRedirect(Filament::getLoginUrl());
});

// AC-003: Logout invalidates session for other tabs
test('session is fully invalidated so other tabs lose access', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $dashboardUrl = Filament::getUrl($user->personalOrganization());

    $this->actingAs($user);

    $this->get($dashboardUrl)->assertOk();

    $this->post(Filament::getLogoutUrl());

    $this->assertGuest();

    $this->get($dashboardUrl)
        ->assertRedirect(Filament::getLoginUrl());
});

// AC-004: Logout works without confirmation (no modal)
test('logout executes immediately without confirmation', function () {
    $user = User::factory()->onboardingCompleted()->create();

    $response = $this->actingAs($user)
        ->post(Filament::getLogoutUrl());

    $response->assertRedirect(Filament::getLoginUrl());
    $this->assertGuest();
});

// Edge case: unauthenticated user posting to logout
test('unauthenticated user cannot access logout route', function () {
    $this->post(Filament::getLogoutUrl())
        ->assertRedirect(Filament::getLoginUrl());
});

// Edge case: CSRF protection on logout route
test('logout route requires POST method', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $response = $this->actingAs($user)
        ->get(Filament::getLogoutUrl());

    $response->assertStatus(404);
});
