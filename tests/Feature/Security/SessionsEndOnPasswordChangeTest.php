<?php

use App\Actions\Account\EndOtherSessions;
use App\Enums\InvoiceStatus;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Auth\SessionGuard;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * @param  array<int, array{string, ?int}>  $rows
 */
function sec28InsertSessions(array $rows): void
{
    foreach ($rows as [$id, $userId]) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }
}

/**
 * Ids of the stored sessions that belong to a user (guest rows written by the test's own requests are left out).
 *
 * @return array<int, string>
 */
function sec28SessionIds(): array
{
    return DB::table('sessions')->whereNotNull('user_id')->orderBy('id')->pluck('id')->all();
}

function sec28HashInSession(User $user): string
{
    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');

    return $guard->hashPasswordForCookie($user->getAuthPassword());
}

beforeEach(function () {
    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'email' => 'ann@example.com',
        'password' => Hash::make('old-password-1'),
    ]);
    $this->bob = User::factory()->create(['email' => 'bob@example.com']);
    $this->org = $this->ann->personalOrganization();
});

test('a stolen session still open after the password was changed elsewhere is signed out on web routes', function (string $kind) {
    $invoice = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => Contact::factory()->create(['organization_id' => $this->org->id])->id,
        'status' => InvoiceStatus::Sent,
    ]);
    $url = $kind === 'pdf' ? route('invoices.pdf', $invoice) : '/settings/profile';

    $this->actingAs($this->ann)
        ->withSession(['password_hash_web' => sec28HashInSession($this->ann)])
        ->get('/settings/profile')
        ->assertOk();

    $this->ann->forceFill(['password' => Hash::make('changed-elsewhere-1')])->save();

    $response = $this->get($url);

    $response->assertRedirect(route('login'));
    expect($response->headers->get('content-type'))->not->toContain('pdf');
    $this->assertGuest();
})->with(['pdf', 'profile']);

test('a stale session on a JSON request gets a 401, not a 500', function () {
    $this->actingAs($this->ann)
        ->withSession(['password_hash_web' => sec28HashInSession($this->ann)]);

    $this->ann->forceFill(['password' => Hash::make('changed-elsewhere-1')])->save();

    $this->getJson('/settings/profile')->assertUnauthorized();
});

test('a session whose hash is current keeps working on web routes', function () {
    $this->actingAs($this->ann)
        ->withSession(['password_hash_web' => sec28HashInSession($this->ann)])
        ->get('/settings/profile')
        ->assertOk();
    $this->assertAuthenticatedAs($this->ann);
});

test('the web middleware group carries the session check', function () {
    expect(Route::getMiddlewareGroups()['web'])->toContain(AuthenticateSession::class);
});

test('a thief remember-me cookie dies with the password change', function () {
    $this->ann->forceFill(['remember_token' => 'old-remember-token-aaaaaaaa'])->save();
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.password')
        ->set('current_password', 'old-password-1')
        ->set('password', 'new-password-22')
        ->set('password_confirmation', 'new-password-22')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $token = $this->ann->refresh()->remember_token;

    expect($token)->not->toBe('old-remember-token-aaaaaaaa')
        ->and($token)->toHaveLength(60);
});

test('ending other sessions deletes only the other rows of that user', function () {
    config(['session.driver' => 'database']);
    sec28InsertSessions([
        ['ann-current', $this->ann->id],
        ['ann-laptop', $this->ann->id],
        ['ann-phone', $this->ann->id],
        ['bob-desk', $this->bob->id],
        ['guest-row', null],
    ]);

    expect(app(EndOtherSessions::class)->handle($this->ann, 'ann-current'))->toBe(2)
        ->and(sec28SessionIds())->toBe(['ann-current', 'bob-desk'])
        ->and(DB::table('sessions')->where('id', 'guest-row')->exists())->toBeTrue();
});

test('ending other sessions again deletes nothing', function () {
    config(['session.driver' => 'database']);
    sec28InsertSessions([['ann-current', $this->ann->id], ['ann-laptop', $this->ann->id], ['bob-desk', $this->bob->id]]);

    app(EndOtherSessions::class)->handle($this->ann, 'ann-current');

    expect(app(EndOtherSessions::class)->handle($this->ann, 'ann-current'))->toBe(0)
        ->and(sec28SessionIds())->toBe(['ann-current', 'bob-desk']);
});

test('without a kept id every session of the user is deleted', function () {
    config(['session.driver' => 'database']);
    sec28InsertSessions([['ann-current', $this->ann->id], ['ann-laptop', $this->ann->id], ['bob-desk', $this->bob->id]]);

    expect(app(EndOtherSessions::class)->handle($this->ann))->toBe(2)
        ->and(sec28SessionIds())->toBe(['bob-desk']);
});

test('a hostile kept id is only compared, never trusted as a scope', function () {
    config(['session.driver' => 'database']);
    sec28InsertSessions([['ann-laptop', $this->ann->id], ['bob-desk', $this->bob->id]]);

    expect(app(EndOtherSessions::class)->handle($this->ann, "bob-desk' OR '1'='1"))->toBe(1)
        ->and(sec28SessionIds())->toBe(['bob-desk']);
});

test('with another session driver nothing is deleted', function () {
    config(['session.driver' => 'array']);
    sec28InsertSessions([['ann-current', $this->ann->id], ['ann-laptop', $this->ann->id], ['ann-phone', $this->ann->id], ['bob-desk', $this->bob->id]]);

    expect(app(EndOtherSessions::class)->handle($this->ann, 'ann-current'))->toBe(0)
        ->and(sec28SessionIds())->toBe(['ann-current', 'ann-laptop', 'ann-phone', 'bob-desk']);
});

test('a password reset ends every session of the user', function () {
    config(['session.driver' => 'database']);
    Notification::fake();
    sec28InsertSessions([['ann-laptop', $this->ann->id], ['ann-phone', $this->ann->id], ['bob-desk', $this->bob->id]]);

    $this->post(route('password.email'), ['email' => $this->ann->email]);

    Notification::assertSentTo($this->ann, QueuedResetPassword::class, function ($notification) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $this->ann->email,
            'password' => 'reset-password-33',
            'password_confirmation' => 'reset-password-33',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });

    expect(sec28SessionIds())->toBe(['bob-desk'])
        ->and(Hash::check('reset-password-33', $this->ann->refresh()->password))->toBeTrue();
});

test('a rejected reset password (too short) ends no session', function () {
    config(['session.driver' => 'database']);
    Notification::fake();
    sec28InsertSessions([['ann-laptop', $this->ann->id]]);

    $this->post(route('password.email'), ['email' => $this->ann->email]);

    Notification::assertSentTo($this->ann, QueuedResetPassword::class, function ($notification) {
        $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $this->ann->email,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        return true;
    });

    expect(sec28SessionIds())->toBe(['ann-laptop'])
        ->and(Hash::check('old-password-1', $this->ann->refresh()->password))->toBeTrue();
});

test('a wrong current password ends nothing', function () {
    config(['session.driver' => 'database']);
    sec28InsertSessions([['ann-laptop', $this->ann->id]]);
    $this->ann->forceFill(['remember_token' => 'old-remember-token-aaaaaaaa'])->save();
    $hash = $this->ann->refresh()->password;
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.password')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password-22')
        ->set('password_confirmation', 'new-password-22')
        ->call('updatePassword')
        ->assertHasErrors(['current_password']);

    $this->ann->refresh();

    expect(sec28SessionIds())->toBe(['ann-laptop'])
        ->and($this->ann->remember_token)->toBe('old-remember-token-aaaaaaaa')
        ->and($this->ann->password)->toBe($hash);
});

test('the settings password change ends the other sessions of the user and spares other users', function () {
    config(['session.driver' => 'database']);
    $currentId = session()->getId();
    sec28InsertSessions([[$currentId, $this->ann->id], ['ann-laptop', $this->ann->id], ['ann-phone', $this->ann->id], ['bob-desk', $this->bob->id]]);
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.password')
        ->set('current_password', 'old-password-1')
        ->set('password', 'new-password-22')
        ->set('password_confirmation', 'new-password-22')
        ->call('updatePassword')
        ->assertHasNoErrors();

    expect(sec28SessionIds())->toEqualCanonicalizing([$currentId, 'bob-desk']);
});
