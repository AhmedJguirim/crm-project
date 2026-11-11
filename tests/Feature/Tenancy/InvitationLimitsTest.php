<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Filament\Pages\Tenancy\RegisterOrganization;
use App\Livewire\Tenancy\InvitationsList;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use App\Support\Tenancy\InvitationLimits;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

function limitsOrganization(User $owner, string $name): Organization
{
    $organization = Organization::factory()->create(['name' => $name, 'created_by' => $owner->id]);
    $organization->members()->attach($owner->id, ['role' => OrganizationRole::Owner->value]);

    return $organization;
}

function limitsInvite(Organization $organization, string $email)
{
    Filament::setTenant($organization);

    return Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('inviteMember')->table(), [
            'email' => $email,
            'role' => OrganizationRole::Member->value,
        ]);
}

function limitsInviteMany(Organization $organization, int $count, string $prefix): void
{
    foreach (range(1, $count) as $number) {
        limitsInvite($organization, "{$prefix}{$number}@example.com")->assertNotified('Invitation sent');
    }
}

beforeEach(function () {
    Mail::fake();

    $this->owner = User::factory()->onboardingCompleted()->create();
    $this->acme = limitsOrganization($this->owner, 'Acme');
    $this->actingAs($this->owner);
});

describe('organization cap', function () {
    test('the 21st invitation in 24 hours is refused and nothing is created or queued', function () {
        limitsInviteMany($this->acme, 20, 'org-cap-');

        limitsInvite($this->acme, 'one-too-many@example.com')
            ->assertNotified('Too many invitations');

        expect(OrganizationInvite::query()->where('organization_id', $this->acme->id)->count())->toBe(20)
            ->and(OrganizationInvite::query()->where('email', 'one-too-many@example.com')->exists())->toBeFalse();
        Mail::assertQueuedCount(20);
    });

    test('the refusal names the organization limit', function () {
        limitsInviteMany($this->acme, 20, 'org-text-');

        limitsInvite($this->acme, 'one-too-many@example.com')
            ->assertNotified(Notification::make()->danger()->title('Too many invitations')->body('This organization has sent 20 invitations in the last 24 hours. Try again later.'));
    });

    test('invitations work again after 24 hours', function () {
        $this->travelTo(now()->startOfSecond());
        limitsInviteMany($this->acme, 20, 'org-window-');

        $this->travelTo(now()->addHours(23));
        limitsInvite($this->acme, 'still-blocked@example.com')->assertNotified('Too many invitations');

        $this->travelTo(now()->addHour()->addMinute());
        limitsInvite($this->acme, 'allowed-again@example.com')->assertNotified('Invitation sent');

        expect(OrganizationInvite::query()->where('email', 'allowed-again@example.com')->exists())->toBeTrue();
    });

    test('another organization is not affected by the first one\'s counter', function () {
        $otherOwner = User::factory()->onboardingCompleted()->create();
        $globex = limitsOrganization($otherOwner, 'Globex');

        limitsInviteMany($this->acme, 20, 'org-other-');
        limitsInvite($this->acme, 'blocked@example.com')->assertNotified('Too many invitations');

        $this->actingAs($otherOwner);
        limitsInvite($globex, 'globex-fine@example.com')->assertNotified('Invitation sent');

        expect(RateLimiter::attempts("invitations:organization:{$globex->id}"))->toBe(1);
    });

    test('a resend counts against the organization cap', function () {
        limitsInviteMany($this->acme, 19, 'org-resend-');
        $invite = OrganizationInvite::query()->where('organization_id', $this->acme->id)->first();

        Livewire::test(InvitationsList::class)
            ->callAction(TestAction::make('resend')->table($invite));

        limitsInvite($this->acme, 'twenty-first@example.com')->assertNotified('Too many invitations');
        Mail::assertQueuedCount(20);
    });

    test('an existing member or a pending invite does not use up the cap', function () {
        $bob = User::factory()->onboardingCompleted()->create(['email' => 'bob@example.com']);
        $this->acme->members()->attach($bob->id, ['role' => OrganizationRole::Member->value]);

        limitsInvite($this->acme, 'BOB@example.com')->assertNotified('Already a member');
        limitsInvite($this->acme, 'carol@example.com')->assertNotified('Invitation sent');
        limitsInvite($this->acme, 'CAROL@example.com')->assertNotified('Invite already pending');

        expect(RateLimiter::attempts("invitations:organization:{$this->acme->id}"))->toBe(1)
            ->and(RateLimiter::attempts("invitations:user:{$this->owner->id}"))->toBe(1);
    });
});

describe('user cap', function () {
    test('the 51st invitation of one user across organizations is refused', function () {
        $globex = limitsOrganization($this->owner, 'Globex');
        $initech = limitsOrganization($this->owner, 'Initech');

        limitsInviteMany($this->acme, 20, 'user-a-');
        limitsInviteMany($globex, 20, 'user-b-');
        limitsInviteMany($initech, 10, 'user-c-');

        limitsInvite($initech, 'user-fifty-one@example.com')
            ->assertNotified(Notification::make()->danger()->title('Too many invitations')->body('You have sent 50 invitations in the last 24 hours. Try again later.'));

        expect(OrganizationInvite::query()->where('email', 'user-fifty-one@example.com')->exists())->toBeFalse();
        Mail::assertQueuedCount(50);
    });

    test('a different user of the same organization has their own counter', function () {
        $admin = User::factory()->onboardingCompleted()->create();
        $this->acme->members()->attach($admin->id, ['role' => OrganizationRole::Admin->value]);

        RateLimiter::hit("invitations:user:{$this->owner->id}", InvitationLimits::WINDOW_SECONDS);

        $this->actingAs($admin);
        limitsInvite($this->acme, 'by-admin@example.com')->assertNotified('Invitation sent');

        expect(RateLimiter::attempts("invitations:user:{$admin->id}"))->toBe(1)
            ->and(RateLimiter::attempts("invitations:user:{$this->owner->id}"))->toBe(1);
    });

    test('a user at the cap is refused even while the organization is below its own', function () {
        foreach (range(1, 50) as $ignored) {
            RateLimiter::hit("invitations:user:{$this->owner->id}", InvitationLimits::WINDOW_SECONDS);
        }

        limitsInvite($this->acme, 'user-capped@example.com')->assertNotified('Too many invitations');

        expect(RateLimiter::attempts("invitations:organization:{$this->acme->id}"))->toBe(0);
    });
});

describe('resend cooldown', function () {
    beforeEach(function () {
        Filament::setTenant($this->acme);
        $this->invite = OrganizationInvite::factory()->withToken('o'.str_repeat('a', 63))->create([
            'organization_id' => $this->acme->id,
            'email' => 'resend-me@example.com',
            'invited_by' => $this->owner->id,
        ]);
    });

    test('a second resend within 10 minutes is refused and changes nothing', function () {
        $this->travelTo(now()->startOfSecond());

        Livewire::test(InvitationsList::class)
            ->callAction(TestAction::make('resend')->table($this->invite))
            ->assertNotified('Invitation resent');

        $hashAfterFirst = $this->invite->fresh()->token_hash;
        $this->travelTo(now()->addMinutes(9));

        Livewire::test(InvitationsList::class)
            ->callAction(TestAction::make('resend')->table($this->invite))
            ->assertNotified('Invitation already resent');

        expect($this->invite->fresh()->token_hash)->toBe($hashAfterFirst)
            ->and(RateLimiter::attempts("invitations:organization:{$this->acme->id}"))->toBe(1);
        Mail::assertQueuedCount(1);
    });

    test('the refusal asks to wait 10 minutes', function () {
        Livewire::test(InvitationsList::class)->callAction(TestAction::make('resend')->table($this->invite));
        Livewire::test(InvitationsList::class)
            ->callAction(TestAction::make('resend')->table($this->invite))
            ->assertNotified(Notification::make()->warning()->title('Invitation already resent')->body('Wait 10 minutes before resending this invitation.'));
    });

    test('the invitation can be resent again after 10 minutes', function () {
        $this->travelTo(now()->startOfSecond());

        Livewire::test(InvitationsList::class)->callAction(TestAction::make('resend')->table($this->invite));

        $this->travelTo(now()->addMinutes(10)->addSecond());

        Livewire::test(InvitationsList::class)
            ->callAction(TestAction::make('resend')->table($this->invite))
            ->assertNotified('Invitation resent');

        Mail::assertQueuedCount(2);
    });

    test('another invitation has its own cooldown', function () {
        $other = OrganizationInvite::factory()->create([
            'organization_id' => $this->acme->id,
            'email' => 'second@example.com',
            'invited_by' => $this->owner->id,
        ]);

        Livewire::test(InvitationsList::class)->callAction(TestAction::make('resend')->table($this->invite));
        Livewire::test(InvitationsList::class)
            ->callAction(TestAction::make('resend')->table($other))
            ->assertNotified('Invitation resent');

        Mail::assertQueuedCount(2);
    });

    test('a resend refused by the cap changes nothing and does not start the cooldown', function () {
        foreach (range(1, 20) as $ignored) {
            RateLimiter::hit("invitations:organization:{$this->acme->id}", InvitationLimits::WINDOW_SECONDS);
        }
        $hash = $this->invite->token_hash;

        Livewire::test(InvitationsList::class)
            ->callAction(TestAction::make('resend')->table($this->invite))
            ->assertNotified('Too many invitations');

        expect($this->invite->fresh()->token_hash)->toBe($hash);
        Mail::assertNothingQueued();

        RateLimiter::clear("invitations:organization:{$this->acme->id}");

        Livewire::test(InvitationsList::class)
            ->callAction(TestAction::make('resend')->table($this->invite))
            ->assertNotified('Invitation resent');
    });

    test('a resend counts against the user cap of the person who clicked, not the original inviter', function () {
        $admin = User::factory()->onboardingCompleted()->create();
        $this->acme->members()->attach($admin->id, ['role' => OrganizationRole::Admin->value]);
        $this->actingAs($admin);

        Livewire::test(InvitationsList::class)->callAction(TestAction::make('resend')->table($this->invite));

        expect(RateLimiter::attempts("invitations:user:{$admin->id}"))->toBe(1)
            ->and(RateLimiter::attempts("invitations:user:{$this->owner->id}"))->toBe(0);
    });

    test('an invitation of another organization cannot be resent from this one', function () {
        $globex = limitsOrganization(User::factory()->onboardingCompleted()->create(), 'Globex');
        $foreign = OrganizationInvite::factory()->create([
            'organization_id' => $globex->id,
            'email' => 'foreign@example.com',
        ]);
        $hash = $foreign->token_hash;

        try {
            Livewire::test(InvitationsList::class)
                ->call('mountAction', 'resend', [], ['table' => true, 'recordKey' => (string) $foreign->id])
                ->call('callMountedAction');
        } catch (Throwable) {
        }

        expect($foreign->fresh()->token_hash)->toBe($hash)
            ->and(RateLimiter::attempts("invitations:organization:{$globex->id}"))->toBe(0);
        Mail::assertNothingQueued();
    });
});

describe('organizations per user', function () {
    beforeEach(function () {
        Filament::setTenant($this->acme);
    });

    function limitsRegister(string $name)
    {
        return Livewire::test(RegisterOrganization::class)
            ->fillForm(['name' => $name])
            ->call('register');
    }

    test('the 11th organization is refused and nothing is created', function () {
        Organization::factory()->count(9)->create(['created_by' => $this->owner->id]);
        $before = Organization::query()->count();

        limitsRegister('Eleventh')
            ->assertNotified(Notification::make()->danger()->title('Organization limit reached')->body('You can create up to 10 organizations.'));

        expect(Organization::query()->count())->toBe($before)
            ->and(Organization::query()->where('name', 'Eleventh')->exists())->toBeFalse();
    });

    test('the 10th organization is still created', function () {
        Organization::factory()->count(8)->create(['created_by' => $this->owner->id]);

        limitsRegister('Tenth')->assertHasNoFormErrors();

        $created = Organization::query()->where('name', 'Tenth')->sole();
        expect($created->created_by)->toBe($this->owner->id)
            ->and($created->getMemberRole($this->owner))->toBe(OrganizationRole::Owner);
    });

    test('the personal organization and organizations of other creators do not count', function () {
        Organization::factory()->personal()->create(['created_by' => $this->owner->id]);
        Organization::factory()->count(8)->create(['created_by' => $this->owner->id]);
        $stranger = User::factory()->onboardingCompleted()->create();
        $joined = Organization::factory()->count(5)->create(['created_by' => $stranger->id]);
        $joined->each(fn (Organization $organization) => $organization->members()->attach($this->owner->id, ['role' => OrganizationRole::Member->value]));

        limitsRegister('Tenth of mine');

        expect(Organization::query()->where('name', 'Tenth of mine')->exists())->toBeTrue();
    });

    test('another user is not affected by the first one\'s count', function () {
        Organization::factory()->count(9)->create(['created_by' => $this->owner->id]);
        $other = User::factory()->onboardingCompleted()->create();
        $this->actingAs($other);
        Filament::setTenant(limitsOrganization($other, 'Other home'));

        limitsRegister('Other first');

        expect(Organization::query()->where('name', 'Other first')->exists())->toBeTrue();
    });
});
