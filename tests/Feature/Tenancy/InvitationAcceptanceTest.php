<?php

use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Auth\Register;
use App\Http\Controllers\InviteAcceptController;
use App\Http\Middleware\StartSession;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Session\Middleware\StartSession as FrameworkStartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-001: Guest user accepts invitation
// ──────────────────────────────────────────────

test('guest clicking accept link is redirected to registration with the invite id in session', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'newuser@example.com',
    ]);

    $response = $this->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getRegistrationUrl());

    expect(session('invite_id'))->toBe($invite->id)
        ->and(session('invite_token'))->toBeNull()
        ->and(session('invite_email'))->toBe('newuser@example.com');
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-002: Existing user accepts matching invite
// ──────────────────────────────────────────────

test('logged-in user with matching email accepts invite when posting the confirmation', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'member@example.com',
    ]);

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'member@example.com',
        'role' => OrganizationRole::Admin,
    ]);

    $this->actingAs($user)
        ->get(route('invite.accept', $token))
        ->assertOk();

    expect($invite->organization->hasMember($user))->toBeFalse();

    $this->actingAs($user)
        ->post(route('invite.accept.store', $token))
        ->assertRedirect();

    // User should now be a member of the invited organization
    expect($invite->organization->hasMember($user))->toBeTrue()
        ->and($invite->organization->getMemberRole($user))->toBe(OrganizationRole::Admin);

    // Invite should be marked as accepted
    expect($invite->fresh()->status)->toBe(InviteStatus::Accepted);
});

test('accepted user appears in organization tenants', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'tenant@example.com',
    ]);

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'tenant@example.com',
    ]);

    $this->actingAs($user)
        ->post(route('invite.accept.store', $token));

    // User should now have 2 organizations: personal + invited
    $user->refresh();
    expect($user->organizations)->toHaveCount(2);
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-003: Logged-in user with non-matching email
// ──────────────────────────────────────────────

test('logged-in user with different email is redirected with error', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'wrong@example.com',
    ]);

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'correct@example.com',
    ]);

    $response = $this->actingAs($user)
        ->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getLoginUrl());

    // User should NOT be a member of the invited organization
    expect($invite->organization->hasMember($user))->toBeFalse();

    // Invite should still be pending
    expect($invite->fresh()->status)->toBe(InviteStatus::Pending);
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-004: Invalid or expired token
// ──────────────────────────────────────────────

test('invalid token redirects to login with error', function () {
    $response = $this->get(route('invite.accept', 'totally-fake-token'));

    $response->assertRedirect(Filament::getLoginUrl());
});

test('expired invite redirects to login with error', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'expired@example.com',
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getLoginUrl());

    // Status should be auto-updated to expired
    expect($invite->fresh()->status)->toBe(InviteStatus::Expired);
});

test('revoked invite redirects to login with error', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->revoked()->create([
        'email' => 'revoked@example.com',
    ]);

    $response = $this->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getLoginUrl());
});

test('already accepted invite redirects to login with error', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->accepted()->create([
        'email' => 'accepted@example.com',
    ]);

    $response = $this->get(route('invite.accept', $token));

    $response->assertRedirect(Filament::getLoginUrl());
});

// ──────────────────────────────────────────────
// TSK-2026-0015 AC-005: Post-acceptance context
// ──────────────────────────────────────────────

test('accepted user can access the new organization dashboard', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'access@example.com',
    ]);

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'access@example.com',
    ]);

    $this->actingAs($user)
        ->post(route('invite.accept.store', $token));

    // User should be able to access the invited org's dashboard
    $this->get(Filament::getUrl($invite->organization))
        ->assertOk();
});

// ──────────────────────────────────────────────
// Edge cases
// ──────────────────────────────────────────────

test('same invite cannot be accepted twice', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'double@example.com',
    ]);

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'double@example.com',
    ]);

    // First acceptance
    $this->actingAs($user)
        ->post(route('invite.accept.store', $token));

    expect($invite->fresh()->status)->toBe(InviteStatus::Accepted);

    // Second attempt should fail (already accepted), on the page and on the POST
    $this->actingAs($user)
        ->get(route('invite.accept', $token))
        ->assertRedirect(Filament::getLoginUrl());

    $this->actingAs($user)
        ->post(route('invite.accept.store', $token))
        ->assertRedirect(Filament::getLoginUrl());

    expect($invite->organization->members()->whereKey($user->id)->count())->toBe(1);
});

test('invite with viewer role assigns viewer role on acceptance', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'viewer@example.com',
    ]);

    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->asViewer()->create([
        'email' => 'viewer@example.com',
    ]);

    $this->actingAs($user)
        ->post(route('invite.accept.store', $token));

    expect($invite->organization->getMemberRole($user))->toBe(OrganizationRole::Viewer);
});

// ──────────────────────────────────────────────
// SEC-13: the guest flow keeps the invite id, not the token
// ──────────────────────────────────────────────

test('a guest who opens the link and registers joins the organization', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->asViewer()->create([
        'email' => 'new@example.com',
    ]);

    $this->get(route('invite.accept', $token))->assertRedirect(Filament::getRegistrationUrl());

    expect(session('invite_id'))->toBe($invite->id)
        ->and(session('invite_token'))->toBeNull();

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'new@example.com')->sole();

    expect($invite->organization->getMemberRole($user))->toBe(OrganizationRole::Viewer)
        ->and($invite->fresh()->status)->toBe(InviteStatus::Accepted)
        ->and(session('invite_id'))->toBeNull();
});

test('an existing user who opens the link and logs in joins the organization', function () {
    $user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'bob@example.com',
        'password' => 'password',
    ]);
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'email' => 'bob@example.com',
    ]);

    $this->get(route('invite.accept', $token))->assertRedirect(Filament::getLoginUrl());

    Livewire::test(Login::class)
        ->fillForm(['email' => 'bob@example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($invite->organization->hasMember($user))->toBeTrue()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Accepted);
});

test('the invite in the session is only accepted for its own email', function () {
    $eve = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'eve@example.com',
        'password' => 'password',
    ]);
    $invite = OrganizationInvite::factory()->create(['email' => 'bob@example.com']);

    $this->withSession(['invite_id' => $invite->id]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'eve@example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($invite->organization->hasMember($eve))->toBeFalse()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Pending)
        ->and(session('invite_id'))->toBeNull();
});

test('an expired invite in the session is not accepted at registration', function () {
    $invite = OrganizationInvite::factory()->expired()->create(['email' => 'late@example.com']);

    $this->withSession(['invite_id' => $invite->id]);

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Late Person',
            'email' => 'late@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::query()->where('email', 'late@example.com')->sole();

    expect($invite->organization->hasMember($user))->toBeFalse();
});

test('a revoked invite in the session is not accepted at login', function (string $state) {
    $bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'bob@example.com',
        'password' => 'password',
    ]);
    $invite = OrganizationInvite::factory()->{$state}()->create(['email' => 'bob@example.com']);
    $status = $invite->status;

    $this->withSession(['invite_id' => $invite->id]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'bob@example.com', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect($invite->organization->hasMember($bob))->toBeFalse()
        ->and($invite->fresh()->status)->toBe($status)
        ->and(session('invite_id'))->toBeNull();
})->with(['revoked' => ['revoked'], 'expired' => ['expired']]);

test('the invite in the session is only accepted for its own email at registration', function () {
    $invite = OrganizationInvite::factory()->create(['email' => 'bob@example.com']);

    $this->withSession(['invite_id' => $invite->id]);

    Livewire::test(Register::class)
        ->fillForm([
            'name' => 'Eve',
            'email' => 'eve@example.com',
            'password' => 'password',
            'passwordConfirmation' => 'password',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $eve = User::query()->where('email', 'eve@example.com')->sole();

    expect($invite->organization->hasMember($eve))->toBeFalse()
        ->and($invite->fresh()->status)->toBe(InviteStatus::Pending)
        ->and(session('invite_id'))->toBeNull();
});

// ──────────────────────────────────────────────
// SEC-17: accepting needs a click (POST); the link only shows a page
// ──────────────────────────────────────────────

describe('confirmation page and POST', function () {
    beforeEach(function () {
        $this->bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'bob@example.com']);
        $this->olga = User::factory()->create(['name' => 'Olga Owner']);
        $this->acme = Organization::factory()->create(['name' => 'Acme']);
        $this->invite = OrganizationInvite::factory()->withToken($this->token = Str::random(64))->create([
            'organization_id' => $this->acme->id,
            'invited_by' => $this->olga->id,
            'email' => 'bob@example.com',
            'role' => OrganizationRole::Admin,
        ]);
    });

    test('opening the link shows the page and changes nothing', function () {
        $this->actingAs($this->bob)
            ->get(route('invite.accept', $this->token))
            ->assertOk()
            ->assertSee('Join Acme')
            ->assertSee('Olga Owner invited you to join Acme as Admin.')
            ->assertSee('Accept invitation')
            ->assertSee('Not now')
            ->assertSee('action="'.route('invite.accept.store', $this->token).'"', false);

        expect($this->acme->hasMember($this->bob))->toBeFalse()
            ->and($this->invite->fresh()->status)->toBe(InviteStatus::Pending);
    });

    test('posting accepts', function () {
        $this->actingAs($this->bob)
            ->post(route('invite.accept.store', $this->token))
            ->assertRedirect(Filament::getUrl($this->acme));

        expect($this->acme->getMemberRole($this->bob))->toBe(OrganizationRole::Admin)
            ->and($this->invite->fresh()->status)->toBe(InviteStatus::Accepted)
            ->and(collect(session('filament.notifications'))->pluck('title')->all())->toContain('Welcome to Acme!');
    });

    test('the POST route is guarded by the web group and auth, and the form carries a CSRF token', function () {
        $middleware = Route::getRoutes()->getByName('invite.accept.store')->gatherMiddleware();

        expect($middleware)->toContain('web', 'auth');

        $this->actingAs($this->bob)
            ->get(route('invite.accept', $this->token))
            ->assertSee('name="_token"', false);
    });

    test('a guest cannot post', function () {
        $this->post(route('invite.accept.store', $this->token))->assertRedirect(route('login'));

        expect($this->invite->fresh()->status)->toBe(InviteStatus::Pending);
    });

    test('posting for another email is refused', function () {
        $eve = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'eve@example.com']);

        $this->actingAs($eve)
            ->post(route('invite.accept.store', $this->token))
            ->assertRedirect(Filament::getLoginUrl());

        expect(collect(session('filament.notifications'))->pluck('body')->all())
            ->toContain('This invitation was sent to bob@example.com, but you are logged in as eve@example.com. Please log out and try again, or use the correct account.')
            ->and($this->acme->hasMember($eve))->toBeFalse()
            ->and($this->invite->fresh()->status)->toBe(InviteStatus::Pending);
    });

    test('posting an unusable invite is refused', function (string $state, string $message) {
        $token = $this->token;

        match ($state) {
            'revoked' => $this->invite->revoke(),
            'expired' => $this->invite->update(['expires_at' => now()->subDay()]),
            'accepted' => $this->invite->update(['status' => InviteStatus::Accepted]),
            default => $token = 'nope',
        };

        $this->actingAs($this->bob)
            ->post(route('invite.accept.store', $token))
            ->assertRedirect(Filament::getLoginUrl());

        expect(collect(session('filament.notifications'))->pluck('body')->all())->toContain($message)
            ->and($this->acme->hasMember($this->bob))->toBeFalse();
    })->with([
        'revoked' => ['revoked', 'This invitation has been revoked. Please contact the organization admin for a new invite.'],
        'expired' => ['expired', 'This invitation has expired. Please request a new invite from the organization admin.'],
        'accepted' => ['accepted', 'This invitation has already been accepted.'],
        'unknown' => ['unknown', 'This invitation link is invalid.'],
    ]);

    test('two acceptances at the same time attach once and neither fails', function () {
        $raced = false;
        $invite = $this->invite;
        $acme = $this->acme;
        $bob = $this->bob;

        DB::listen(function (QueryExecuted $query) use (&$raced, $invite, $acme, $bob): void {
            if ($raced || ! str_contains($query->sql, 'from "organization_invites"')) {
                return;
            }

            $raced = true;
            DB::table('organization_invites')->where('id', $invite->id)->update(['status' => InviteStatus::Accepted->value]);
            DB::table('organization_user')->insert([
                'organization_id' => $acme->id,
                'user_id' => $bob->id,
                'role' => OrganizationRole::Admin->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->actingAs($this->bob)
            ->post(route('invite.accept.store', $this->token))
            ->assertRedirect(Filament::getLoginUrl());

        expect($raced)->toBeTrue()
            ->and($acme->members()->whereKey($bob->id)->count())->toBe(1)
            ->and(collect(session('filament.notifications'))->pluck('body')->all())->toContain('This invitation has already been accepted.')
            ->and(collect(session('filament.notifications'))->pluck('title')->all())->not->toContain('Welcome to Acme!');
    });

    test('acceptInvite attaches nothing for an invite that is no longer valid', function () {
        $stale = OrganizationInvite::query()->find($this->invite->id);
        $this->invite->revoke();

        $accepted = app(InviteAcceptController::class)->acceptInvite($stale, $this->bob);

        expect($accepted)->toBeFalse()
            ->and($this->acme->hasMember($this->bob))->toBeFalse()
            ->and(session('filament.notifications'))->toBeNull();
    });
});

describe('the plain token never reaches the session (BUG-62)', function () {
    beforeEach(function () {
        $this->invite = OrganizationInvite::factory()->withToken($this->token = Str::random(64))->create(['email' => 'bob@example.com']);
    });

    test('the link is not recorded for', function (string $who) {
        $bob = fn () => User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'bob@example.com']);

        match ($who) {
            'guest with an account' => $bob(),
            'guest without an account' => null,
            'bob logged in' => $this->actingAs($bob()),
            'eve logged in' => $this->actingAs(User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'eve@example.com'])),
        };

        $this->get(route('invite.accept', $this->token));

        expect(json_encode(session()->all()))->not->toContain($this->token)
            ->not->toContain(hash('sha256', $this->token))
            ->and((string) session('_previous.url'))->not->toContain('/invite/accept/');
    })->with(['guest with an account', 'guest without an account', 'bob logged in', 'eve logged in']);

    test('other pages still record the previous URL', function () {
        $this->get(Filament::getLoginUrl());

        expect(session('_previous.url'))->toBe(Filament::getLoginUrl());
    });

    test('the framework session middleware resolves to the app one', function () {
        expect(app(FrameworkStartSession::class))->toBeInstanceOf(StartSession::class);
    });
});
