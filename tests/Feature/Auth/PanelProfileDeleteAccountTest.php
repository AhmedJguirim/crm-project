<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Auth\EditProfile;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

const PPDA_REFUSAL = 'Transfer or delete these organizations first: Acme Studio, Zeta Labs.';

/**
 * @param  array<string, mixed>  $data
 */
function ppdaDelete(array $data)
{
    return Livewire::test(EditProfile::class)
        ->callAction(TestAction::make('deleteAccount')->schemaComponent('deleteAccountSection', 'content'), data: $data);
}

function ppdaOrganizationExists(int $id): bool
{
    return Organization::query()->withoutGlobalScope('organization')->whereKey($id)->exists();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 09:00:00', 'UTC'));
    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'name' => 'Ann',
        'email' => 'ann@example.com',
        'password' => Hash::make('password'),
    ]);
    $this->bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create([
        'name' => 'Bob',
        'email' => 'bob@example.com',
        'password' => Hash::make('bobs-password-1'),
    ]);
    $this->annPersonal = $this->ann->personalOrganization();
    $this->bobPersonal = $this->bob->personalOrganization();
    $this->shared = function (string $name, User $owner, User $member, OrganizationRole $memberRole = OrganizationRole::Member): Organization {
        $organization = Organization::factory()->create(['name' => $name, 'created_by' => $owner->id]);
        $organization->members()->attach($owner->id, ['role' => OrganizationRole::Owner->value]);
        $organization->members()->attach($member->id, ['role' => $memberRole->value]);

        return $organization;
    };
    $this->actingAs($this->ann);
});

test('the profile shows the delete section', function () {
    $html = $this->get(Filament::getProfileUrl())
        ->assertOk()
        ->assertSee('Delete account')
        ->assertSee('Delete your account and your personal workspace')
        ->assertSee('This cannot be undone. If you own an organization other people work in, transfer it or delete it first.')
        ->getContent();

    expect(strpos($html, 'Delete account'))->toBeGreaterThan(strpos($html, 'Change password'));

    Livewire::test(EditProfile::class)
        ->assertActionVisible(TestAction::make('deleteAccount')->schemaComponent('deleteAccountSection', 'content'));
});

test('a sole member deletes the account with every organization she alone uses', function () {
    $log = fakeSecurityLog();
    $solo = Organization::factory()->create(['name' => 'Solo Shop', 'created_by' => $this->ann->id]);
    $solo->members()->attach($this->ann->id, ['role' => OrganizationRole::Owner->value]);
    $client = Contact::factory()->create(['organization_id' => $solo->id, 'name' => 'Shop Client']);
    $annId = $this->ann->id;

    ppdaDelete(['password' => 'password'])
        ->assertHasNoActionErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    $entries = securityLogEntries($log);

    expect(User::query()->where('email', 'ann@example.com')->exists())->toBeFalse()
        ->and(ppdaOrganizationExists($this->annPersonal->id))->toBeFalse()
        ->and(ppdaOrganizationExists($solo->id))->toBeFalse()
        ->and(Contact::query()->withoutGlobalScope('organization')->whereKey($client->id)->exists())->toBeFalse()
        ->and(array_column($entries, 'event'))->toBe(['account.deleted', 'auth.logout'])
        ->and($entries[0]['level'])->toBe('warning')
        ->and($entries[0]['context'])->toMatchArray(['user_id' => $annId, 'deleted_organizations' => 2, 'left_organizations' => 0]);
});

test('the session of the deleted account is invalidated and its token regenerated', function () {
    session()->put('ppda_marker', 'x');
    $sessionId = session()->getId();
    $token = session()->token();

    ppdaDelete(['password' => 'password'])->assertRedirect(route('home'));

    expect(session()->getId())->not->toBe($sessionId)
        ->and(session()->token())->toBeString()->not->toBe($token)
        ->and(session()->has('ppda_marker'))->toBeFalse();
});

test('a member of a shared organization deletes the account and the team keeps the work', function () {
    $log = fakeSecurityLog();
    $acme = ($this->shared)('Acme Studio', $this->bob, $this->ann);
    $deal = Deal::factory()->create([
        'organization_id' => $acme->id,
        'title' => 'Website redesign',
        'created_by' => $this->ann->id,
        'owner_id' => $this->bob->id,
    ]);
    OrganizationInvite::factory()->create(['organization_id' => $acme->id, 'email' => 'ANN@example.com', 'invited_by' => $this->bob->id]);

    ppdaDelete(['password' => 'password'])->assertHasNoActionErrors()->assertRedirect(route('home'));

    $deal = Deal::query()->withoutGlobalScope('organization')->findOrFail($deal->id);

    expect(ppdaOrganizationExists($acme->id))->toBeTrue()
        ->and($acme->members()->pluck('users.id')->all())->toBe([$this->bob->id])
        ->and($acme->members()->first()->pivot->role)->toBe(OrganizationRole::Owner->value)
        ->and($deal->created_by)->toBeNull()
        ->and($deal->owner_id)->toBe($this->bob->id)
        ->and(OrganizationInvite::query()->where('organization_id', $acme->id)->whereRaw('lower(email) = ?', ['ann@example.com'])->exists())->toBeFalse()
        ->and(securityLogEntries($log)[0]['context'])->toMatchArray(['deleted_organizations' => 1, 'left_organizations' => 1]);
});

test('the owner of a shared organization is refused and nothing is written', function () {
    $log = fakeSecurityLog();
    $dave = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Dave Diaz']);
    $zeta = ($this->shared)('Zeta Labs', $this->ann, $dave);
    $acme = ($this->shared)('Acme Studio', $this->ann, $this->bob);

    ppdaDelete(['password' => 'password'])
        ->assertNoRedirect()
        ->assertHasNoActionErrors()
        ->assertSet('mountedActions', []);

    $this->assertAuthenticatedAs($this->ann);

    $notifications = new Notifications;
    $notifications->mount();

    expect($notifications->notifications)->toHaveCount(1)
        ->and($notifications->notifications->last()->getTitle())->toBe(PPDA_REFUSAL)
        ->and($notifications->notifications->last()->getDuration())->toBe('persistent');

    expect(User::query()->whereKey($this->ann->id)->exists())->toBeTrue()
        ->and(ppdaOrganizationExists($this->annPersonal->id))->toBeTrue()
        ->and($zeta->members()->count())->toBe(2)
        ->and($acme->members()->count())->toBe(2)
        ->and($this->annPersonal->members()->count())->toBe(1)
        ->and(array_column(securityLogEntries($log), 'event'))->toBe(['account.deletion_refused'])
        ->and(securityLogEntries($log)[0]['context'])->toMatchArray(['organizations' => 2]);
});

test('invalid password input deletes nothing and logs nothing', function (string $password, ?string $message) {
    $log = fakeSecurityLog();

    $page = ppdaDelete(['password' => $password])->assertHasActionErrors(['password']);

    if ($message !== null) {
        expect($page->errors()->get('mountedActions.0.data.password'))->toBe([$message]);
    }

    $this->assertAuthenticatedAs($this->ann);

    expect(User::query()->whereKey($this->ann->id)->exists())->toBeTrue()
        ->and(ppdaOrganizationExists($this->annPersonal->id))->toBeTrue()
        ->and(securityLogEntries($log))->toBe([]);
})->with([
    'wrong' => ['wrong', 'The password is incorrect.'],
    'empty' => ['', null],
]);

test('her files go and his stay', function () {
    Storage::fake('local');
    $annFile = 'exports/contacts-o'.$this->annPersonal->id.'-u'.$this->ann->id.'-'.str_repeat('a', 40).'.csv';
    $bobFile = 'exports/contacts-o'.$this->bobPersonal->id.'-u'.$this->bob->id.'-'.str_repeat('b', 40).'.csv';
    Storage::disk('local')->put($annFile, 'x');
    Storage::disk('local')->put($bobFile, 'x');

    ppdaDelete(['password' => 'password'])->assertRedirect(route('home'));

    Storage::disk('local')->assertMissing($annFile);
    Storage::disk('local')->assertExists($bobFile);
});

describe('hostile', function () {
    test('a sixth delete attempt within a minute is throttled before validation', function () {
        $page = Livewire::test(EditProfile::class);
        $action = TestAction::make('deleteAccount')->schemaComponent('deleteAccountSection', 'content');

        foreach (range(1, 5) as $ignored) {
            $page->callAction($action, data: ['password' => 'wrong']);
        }

        $page->callAction($action, data: ['password' => 'password'])->assertNotified('Too many attempts');

        expect(User::query()->whereKey($this->ann->id)->exists())->toBeTrue()
            ->and(ppdaOrganizationExists($this->annPersonal->id))->toBeTrue();
    });

    test('the per-IP limit holds across users on the same IP', function () {
        foreach (range(1, 5) as $ignored) {
            ppdaDelete(['password' => 'wrong']);
        }

        $this->actingAs($this->bob);

        ppdaDelete(['password' => 'bobs-password-1'])->assertNotified('Too many attempts');

        expect(User::query()->whereKey($this->bob->id)->exists())->toBeTrue();
    });

    test('the per-user limit holds when the IP changes', function () {
        foreach (range(1, 5) as $ignored) {
            RateLimiter::hit('profile-account-delete:'.$this->ann->id);
        }

        ppdaDelete(['password' => 'password'])->assertNotified('Too many attempts');

        expect(User::query()->whereKey($this->ann->id)->exists())->toBeTrue();
    });

    test('the per-user limit is per user', function () {
        foreach (range(1, 5) as $ignored) {
            RateLimiter::hit('profile-account-delete:'.$this->bob->id);
        }

        ppdaDelete(['password' => 'password'])->assertRedirect(route('home'));

        expect(User::query()->whereKey($this->ann->id)->exists())->toBeFalse();
    });

    test('forged ids in the modal data never reach another account', function () {
        $bobHash = $this->bob->password;

        ppdaDelete(['password' => 'password', 'id' => $this->bob->id, 'user_id' => $this->bob->id, 'email' => 'bob@example.com'])
            ->assertRedirect(route('home'));

        expect(User::query()->whereKey($this->ann->id)->exists())->toBeFalse()
            ->and($this->bob->fresh()->password)->toBe($bobHash)
            ->and(ppdaOrganizationExists($this->bobPersonal->id))->toBeTrue();
    });

    test('a forged array for the password is refused without a 500', function () {
        ppdaDelete(['password' => ['password']])->assertHasActionErrors(['password']);

        $this->assertAuthenticatedAs($this->ann);

        expect(User::query()->whereKey($this->ann->id)->exists())->toBeTrue();
    });

    test('an organization name with markup shows as typed in the refusal', function () {
        ($this->shared)('<b>Acme</b> & Co', $this->ann, $this->bob);

        ppdaDelete(['password' => 'password'])
            ->assertNotified('Transfer or delete these organizations first: &lt;b&gt;Acme&lt;/b&gt; &amp; Co.');

        expect(User::query()->whereKey($this->ann->id)->exists())->toBeTrue();
    });
});
