<?php

use App\Enums\AccountSecurityEvent;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\User;
use App\Notifications\AccountSecurityNotification;
use App\Support\Auth\VerificationMails;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

const PPPM_REFUSAL = 'You have asked for 5 verification emails in the last 24 hours. Try again later.';

/**
 * @param  array<string, mixed>  $data
 */
function pppmChange(array $data)
{
    return Livewire::test(EditProfile::class)
        ->callAction(TestAction::make('changePassword')->schemaComponent('passwordSection', 'content'), data: $data);
}

/**
 * @return array<string, string>
 */
function pppmValid(string $new = 'new-password-22'): array
{
    return ['currentPassword' => 'password', 'password' => $new, 'passwordConfirmation' => $new];
}

/**
 * @param  array<int, array{string, ?int}>  $rows
 */
function pppmInsertSessions(array $rows): void
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
 * @return array<int, string>
 */
function pppmSessionIds(): array
{
    return DB::table('sessions')->whereNotNull('user_id')->orderBy('id')->pluck('id')->all();
}

function pppmSentCount(User $user): int
{
    return Notification::sent($user, AccountSecurityNotification::class)->count();
}

/**
 * @param  list<array{event: string, level: string, context: array<string, mixed>}>  $entries
 * @return list<array{event: string, level: string, context: array<string, mixed>}>
 */
function pppmEntriesNamed(array $entries, string $event): array
{
    return array_values(array_filter($entries, fn (array $entry): bool => $entry['event'] === $event));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00:00', 'UTC'));
    Notification::fake();
    $this->verifiedAt = CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC');
    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'name' => 'Ann',
        'email' => 'ann@example.com',
        'password' => Hash::make('password'),
        'email_verified_at' => $this->verifiedAt,
        'task_reminder_mails' => true,
    ]);
    $this->bob = User::factory()->create(['email' => 'bob@example.com', 'password' => Hash::make('bobs-password-1')]);
    $this->actingAs($this->ann);
});

test('the page shows the switch, on, and the password section', function () {
    $html = $this->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('Email notifications')
        ->assertSee('Choose which emails you get from the CRM')
        ->assertSee('Task reminder emails')
        ->assertSee('Password')
        ->assertSee('Changing your password signs you out on your other devices.')
        ->assertSee('Change password')
        ->getContent();

    expect(strpos($html, 'Email notifications'))->toBeGreaterThan(strpos($html, 'Update your name and email address'))
        ->and(strpos($html, 'Change password'))->toBeLessThan(strpos($html, 'Authenticator app'));

    Livewire::test(EditProfile::class)->assertSet('data.task_reminder_mails', true);
});

test('the switch is saved with "Save changes" and needs no password', function (bool $before, bool $after) {
    $this->ann->forceFill(['task_reminder_mails' => $before])->save();

    Livewire::test(EditProfile::class)
        ->set('data.task_reminder_mails', $after)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved');

    expect($this->ann->fresh())->task_reminder_mails->toBe($after)->name->toBe('Ann')->email->toBe('ann@example.com');
})->with([[true, false], [false, true]]);

test('saving changes only the signed-in user', function () {
    $this->bob->forceFill(['task_reminder_mails' => true])->save();
    $before = $this->ann->only(['name', 'email', 'password', 'email_verified_at']);

    Livewire::test(EditProfile::class)
        ->set('data.task_reminder_mails', false)
        ->call('save')
        ->assertHasNoFormErrors();

    $ann = $this->ann->fresh();

    expect($this->bob->fresh()->task_reminder_mails)->toBeTrue()
        ->and($ann->task_reminder_mails)->toBeFalse()
        ->and($ann->only(['name', 'email', 'password', 'email_verified_at']))->toEqual($before);
});

test('the switch is saved together with a new address request', function () {
    Livewire::test(EditProfile::class)
        ->set('data.task_reminder_mails', false)
        ->set('data.email', 'ann.new@example.com')
        ->set('data.currentPassword', 'password')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->ann->fresh())->task_reminder_mails->toBeFalse()->pending_email->toBe('ann.new@example.com')->email->toBe('ann@example.com');
});

test('a refused email change saves the switch neither', function () {
    foreach (range(1, 5) as $ignored) {
        expect(VerificationMails::claim($this->ann))->toBeNull();
    }

    $page = Livewire::test(EditProfile::class)
        ->set('data.task_reminder_mails', false)
        ->set('data.email', 'ann.new@example.com')
        ->set('data.currentPassword', 'password')
        ->call('save');

    expect($page->errors()->get('data.email'))->toBe([PPPM_REFUSAL])
        ->and($this->ann->fresh())->task_reminder_mails->toBeTrue()->pending_email->toBeNull();
});

test('switched off on the panel, no task mail goes out', function () {
    Livewire::test(EditProfile::class)->set('data.task_reminder_mails', false)->call('save')->assertHasNoFormErrors();

    expect($this->ann->fresh()->wantsTaskReminderMails())->toBeFalse();
});

test('the password is changed and this device stays signed in', function () {
    /** @var SessionGuard $guard */
    $guard = Auth::guard('web');
    session()->put('password_hash_web', $guard->hashPasswordForCookie($this->ann->getAuthPassword()));
    $this->ann->forceFill(['remember_token' => 'old-remember-token-aaaaaaaa'])->save();

    pppmChange(pppmValid())
        ->assertHasNoActionErrors()
        ->assertNotified('Password updated. You are signed out on your other devices.');

    $ann = $this->ann->fresh();

    expect(Hash::check('new-password-22', $ann->password))->toBeTrue()
        ->and(session('password_hash_web'))->toBe($guard->hashPasswordForCookie($ann->getAuthPassword()))
        ->and($ann->remember_token)->not->toBe('old-remember-token-aaaaaaaa')->toHaveLength(60);

    $this->get(Filament::getUrl($this->ann->personalOrganization()))->assertOk();
    $this->assertAuthenticatedAs($ann);
});

test('other sessions of Ann end, Bob\'s do not', function () {
    config(['session.driver' => 'database']);
    pppmInsertSessions([['ann-other', $this->ann->id], ['bob-1', $this->bob->id]]);

    pppmChange(pppmValid())->assertHasNoActionErrors();

    expect(pppmSessionIds())->not->toContain('ann-other')->toContain('bob-1');
});

test('the change is logged and mailed', function () {
    $log = fakeSecurityLog();

    pppmChange(pppmValid())->assertHasNoActionErrors();

    $entries = pppmEntriesNamed(securityLogEntries($log), 'account.password_changed');
    expect($entries)->toHaveCount(1)
        ->and($entries[0]['context']['user_id'])->toBe($this->ann->id)
        ->and(json_encode(securityLogEntries($log)))->not->toContain('new-password-22');

    Notification::assertSentToTimes($this->ann, AccountSecurityNotification::class, 1);
    expect(Notification::sent($this->ann, AccountSecurityNotification::class)->first()->event)->toBe(AccountSecurityEvent::PasswordChanged);
});

test('a pending email change survives a password change', function () {
    $this->ann->forceFill(['pending_email' => 'ann.new@example.com'])->save();

    pppmChange(pppmValid())->assertHasNoActionErrors();

    expect($this->ann->fresh()->pending_email)->toBe('ann.new@example.com');
});

test('invalid input is refused and changes nothing', function (string $current, string $new, string $confirm, string $field, ?string $message) {
    $log = fakeSecurityLog();

    $page = pppmChange(['currentPassword' => $current, 'password' => $new, 'passwordConfirmation' => $confirm])
        ->assertHasActionErrors([$field]);

    if ($message !== null) {
        expect($page->errors()->get('mountedActions.0.data.'.$field)[0] ?? null)->toBe($message);
    }

    expect(Hash::check('password', $this->ann->fresh()->password))->toBeTrue()
        ->and(pppmSentCount($this->ann))->toBe(0)
        ->and(pppmEntriesNamed(securityLogEntries($log), 'account.password_changed'))->toBe([]);
})->with([
    'wrong current' => ['wrong', 'new-password-22', 'new-password-22', 'currentPassword', 'The password is incorrect.'],
    'empty current' => ['', 'new-password-22', 'new-password-22', 'currentPassword', null],
    'too short' => ['password', 'short', 'short', 'password', 'The new password field must be at least 8 characters.'],
    'mismatch' => ['password', 'new-password-22', 'new-password-33', 'password', null],
    'empty confirm' => ['password', 'new-password-22', '', 'passwordConfirmation', null],
]);

describe('hostile', function () {
    test('a sixth password attempt within a minute is throttled before validation', function () {
        $page = Livewire::test(EditProfile::class);

        foreach (range(1, 5) as $ignored) {
            $page->callAction(TestAction::make('changePassword')->schemaComponent('passwordSection', 'content'), data: ['currentPassword' => 'wrong', 'password' => 'new-password-22', 'passwordConfirmation' => 'new-password-22']);
        }

        $page->callAction(TestAction::make('changePassword')->schemaComponent('passwordSection', 'content'), data: pppmValid())
            ->assertNotified('Too many attempts');

        expect(Hash::check('password', $this->ann->fresh()->password))->toBeTrue()
            ->and(pppmSentCount($this->ann))->toBe(0);
    });

    test('the per-IP limit holds across users on the same IP', function () {
        foreach (range(1, 5) as $ignored) {
            pppmChange(['currentPassword' => 'wrong', 'password' => 'new-password-22', 'passwordConfirmation' => 'new-password-22']);
        }

        $bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['password' => Hash::make('bobs-password-1')]);
        $this->actingAs($bob);

        pppmChange(['currentPassword' => 'bobs-password-1', 'password' => 'bobs-new-password-2', 'passwordConfirmation' => 'bobs-new-password-2'])
            ->assertNotified('Too many attempts');

        expect(Hash::check('bobs-password-1', $bob->fresh()->password))->toBeTrue();
    });

    test('the per-user limit holds when the IP changes', function () {
        foreach (range(1, 5) as $ignored) {
            RateLimiter::hit('profile-password-change:'.$this->ann->id);
        }

        pppmChange(pppmValid())->assertNotified('Too many attempts');

        expect(Hash::check('password', $this->ann->fresh()->password))->toBeTrue()
            ->and(pppmSentCount($this->ann))->toBe(0);
    });

    test('the per-user limit is per user', function () {
        foreach (range(1, 5) as $ignored) {
            RateLimiter::hit('profile-password-change:'.$this->bob->id);
        }

        pppmChange(pppmValid())->assertHasNoActionErrors()->assertNotified('Password updated. You are signed out on your other devices.');

        expect(Hash::check('new-password-22', $this->ann->fresh()->password))->toBeTrue();
    });

    test('forged extra modal fields are ignored', function () {
        $bobHash = $this->bob->password;

        pppmChange([...pppmValid(), 'email' => 'mallory@example.com', 'email_verified_at' => null, 'id' => $this->bob->id])
            ->assertHasNoActionErrors();

        $ann = $this->ann->fresh();
        expect($ann->email)->toBe('ann@example.com')
            ->and($ann->email_verified_at->equalTo($this->verifiedAt))->toBeTrue()
            ->and(Hash::check('new-password-22', $ann->password))->toBeTrue()
            ->and($this->bob->fresh()->password)->toBe($bobHash);
    });

    test('a forged array for the new password is refused without a 500', function () {
        pppmChange(['currentPassword' => 'password', 'password' => ['x'], 'passwordConfirmation' => 'x'])
            ->assertHasActionErrors(['password']);

        expect(Hash::check('password', $this->ann->fresh()->password))->toBeTrue()
            ->and(pppmSentCount($this->ann))->toBe(0);
    });

    test('a forged array for the current password is refused without a 500', function () {
        pppmChange(['currentPassword' => ['password'], 'password' => 'new-password-22', 'passwordConfirmation' => 'new-password-22'])
            ->assertHasActionErrors(['currentPassword']);

        expect(Hash::check('password', $this->ann->fresh()->password))->toBeTrue();
    });

    test('a forged switch value is refused', function (mixed $value) {
        Livewire::test(EditProfile::class)
            ->set('data.task_reminder_mails', $value)
            ->call('save')
            ->assertHasFormErrors(['task_reminder_mails']);

        expect($this->ann->fresh()->task_reminder_mails)->toBeTrue()
            ->and(Hash::check('password', $this->ann->fresh()->password))->toBeTrue();
    })->with([
        'string' => ['maybe'],
        'array' => [['0']],
    ]);
});

describe('task mail switch rules', function () {
    test('a new user reads the switch as on before any refresh', function () {
        expect(new User)->task_reminder_mails->toBeTrue()
            ->and(User::factory()->make()->task_reminder_mails)->toBeTrue()
            ->and($this->ann->task_reminder_mails)->toBeTrue()
            ->and($this->ann->fresh()->task_reminder_mails)->toBeTrue();
    });

    test('the switch is not mass assignable', function () {
        $user = User::create(['name' => 'Mass', 'email' => 'mass@example.com', 'password' => 'secret-pass-123', 'task_reminder_mails' => false]);

        expect($user->fresh()->task_reminder_mails)->toBeTrue()
            ->and((new User)->isFillable('task_reminder_mails'))->toBeFalse();
    });
});
