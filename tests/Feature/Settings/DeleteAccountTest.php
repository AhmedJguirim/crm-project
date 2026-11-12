<?php

use App\Actions\Account\DeleteUserAccount;
use App\Enums\OrganizationRole;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Task;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * SEC-27: deleting an account never destroys other people's data.
 * There is no tenant in these tests: every existence check removes the organization scope.
 */

beforeEach(function () {
    $this->alice = User::factory()->create(['name' => 'Alice Archer', 'email' => 'alice@example.com']);
    $this->alicePersonal = $this->alice->createPersonalOrganization();

    $this->acme = Organization::factory()->create(['name' => 'Acme Studio', 'created_by' => $this->alice->id]);
    $this->acme->members()->attach($this->alice->id, ['role' => OrganizationRole::Owner->value]);

    $this->bob = User::factory()->create(['name' => 'Bob Baker', 'email' => 'bob@example.com']);
    $this->bobPersonal = $this->bob->createPersonalOrganization();
    $this->acme->members()->attach($this->bob->id, ['role' => OrganizationRole::Member->value]);

    $this->deal = Deal::factory()->create([
        'organization_id' => $this->acme->id,
        'title' => 'Website redesign',
        'created_by' => $this->bob->id,
        'owner_id' => $this->alice->id,
    ]);
    $this->task = Task::factory()->create([
        'organization_id' => $this->acme->id,
        'title' => 'Send proposal',
        'created_by' => $this->bob->id,
        'owner_id' => $this->bob->id,
    ]);
    $this->personalLead = Contact::factory()->create([
        'organization_id' => $this->bobPersonal->id,
        'name' => 'Personal Lead',
        'email' => 'lead@example.com',
    ]);

    $this->deleteAccountAs = function (User $user, string $password = 'password') {
        $this->actingAs($user);

        return Livewire::test('pages::settings.delete-user-form')
            ->set('password', $password)
            ->call('deleteUser');
    };
    $this->organizationExists = fn (Organization $organization): bool => Organization::query()->whereKey($organization->id)->exists();
});

test('a non-owner deletes their account and the team keeps their work', function (OrganizationRole $role) {
    $this->acme->members()->updateExistingPivot($this->bob->id, ['role' => $role->value]);
    $bobId = $this->bob->id;
    OrganizationInvite::factory()->create([
        'organization_id' => $this->acme->id,
        'email' => 'BOB@example.com',
        'invited_by' => $this->alice->id,
    ]);

    ($this->deleteAccountAs)($this->bob)->assertHasNoErrors()->assertRedirect('/');

    expect(auth()->check())->toBeFalse()
        ->and(User::query()->whereKey($bobId)->exists())->toBeFalse()
        ->and(User::query()->where('email', 'bob@example.com')->exists())->toBeFalse()
        ->and(($this->organizationExists)($this->acme))->toBeTrue()
        ->and($this->acme->members()->pluck('users.id')->all())->toBe([$this->alice->id])
        ->and($this->acme->members()->first()->pivot->role)->toBe(OrganizationRole::Owner->value);

    $deal = Deal::query()->withoutGlobalScope('organization')->findOrFail($this->deal->id);
    $task = Task::query()->withoutGlobalScope('organization')->findOrFail($this->task->id);

    expect($deal->created_by)->toBeNull()
        ->and($deal->owner_id)->toBe($this->alice->id)
        ->and($task->created_by)->toBeNull()
        ->and($task->owner_id)->toBeNull()
        ->and(($this->organizationExists)($this->bobPersonal))->toBeFalse()
        ->and(Contact::query()->withoutGlobalScope('organization')->whereKey($this->personalLead->id)->exists())->toBeFalse()
        ->and(OrganizationInvite::query()->where('organization_id', $this->acme->id)->whereEmailIgnoringCase('bob@example.com')->exists())->toBeFalse();
})->with([
    'admin' => OrganizationRole::Admin,
    'member' => OrganizationRole::Member,
    'viewer' => OrganizationRole::Viewer,
]);

test('the creator of an organization who transferred it deletes their account and the organization stays', function () {
    $carol = User::factory()->create(['name' => 'Carol Chen', 'email' => 'carol@example.com']);
    $carol->createPersonalOrganization();
    $north = Organization::factory()->create(['name' => 'Studio North', 'created_by' => $carol->id]);
    $north->members()->attach($this->alice->id, ['role' => OrganizationRole::Owner->value]);
    $north->members()->attach($carol->id, ['role' => OrganizationRole::Admin->value]);
    $client = Contact::factory()->create(['organization_id' => $north->id, 'name' => 'Client One', 'email' => 'client1@example.com']);
    $invoice = Invoice::factory()->create(['organization_id' => $north->id, 'contact_id' => $client->id]);

    ($this->deleteAccountAs)($carol)->assertHasNoErrors()->assertRedirect('/');

    expect(Organization::query()->findOrFail($north->id)->created_by)->toBeNull()
        ->and(Contact::query()->withoutGlobalScope('organization')->whereKey($client->id)->exists())->toBeTrue()
        ->and(Invoice::query()->withoutGlobalScope('organization')->whereKey($invoice->id)->exists())->toBeTrue()
        ->and($north->members()->pluck('users.id')->all())->toBe([$this->alice->id]);
});

test('the owner of a shared organization is refused with the sorted names and nothing is written', function () {
    $zeta = Organization::factory()->create(['name' => 'Zeta Labs', 'created_by' => $this->alice->id]);
    $zeta->members()->attach($this->alice->id, ['role' => OrganizationRole::Owner->value]);
    $dave = User::factory()->create(['name' => 'Dave Diaz']);
    $zeta->members()->attach($dave->id, ['role' => OrganizationRole::Member->value]);
    $membershipsBefore = $this->alice->organizations()->count();

    $component = ($this->deleteAccountAs)($this->alice);

    $component->assertHasErrors(['organizations'])->assertNoRedirect()->assertSet('password', '');
    expect($component->errors()->first('organizations'))->toBe('Transfer or delete these organizations first: Acme Studio, Zeta Labs.')
        ->and(auth()->id())->toBe($this->alice->id)
        ->and(User::query()->whereKey($this->alice->id)->exists())->toBeTrue()
        ->and(($this->organizationExists)($this->acme))->toBeTrue()
        ->and(($this->organizationExists)($zeta))->toBeTrue()
        ->and(($this->organizationExists)($this->alicePersonal))->toBeTrue()
        ->and($this->alice->organizations()->count())->toBe($membershipsBefore)
        ->and($this->acme->members()->count())->toBe(2)
        ->and(Deal::query()->withoutGlobalScope('organization')->whereKey($this->deal->id)->exists())->toBeTrue();
});

test('the blocking organization names are sorted case-insensitively whatever the creation order', function (array $names) {
    foreach ($names as $name) {
        $organization = Organization::factory()->create(['name' => $name, 'created_by' => $this->alice->id]);
        $organization->members()->attach($this->alice->id, ['role' => OrganizationRole::Owner->value]);
        $organization->members()->attach($this->bob->id, ['role' => OrganizationRole::Member->value]);
    }

    $component = ($this->deleteAccountAs)($this->alice);

    expect($component->errors()->first('organizations'))->toBe('Transfer or delete these organizations first: Acme Studio, beta works, Zeta Labs.');
})->with([
    'alphabetical creation order' => [['beta works', 'Zeta Labs']],
    'reverse creation order' => [['Zeta Labs', 'beta works']],
]);

test('a personal workspace with another member blocks the deletion too', function () {
    $gina = User::factory()->create(['name' => 'Gina Gray']);
    $workspace = $gina->createPersonalOrganization();
    $workspace->update(['name' => "Gina Gray's Workspace"]);
    $workspace->members()->attach($this->bob->id, ['role' => OrganizationRole::Member->value]);

    $component = ($this->deleteAccountAs)($gina);

    expect($component->errors()->first('organizations'))->toBe("Transfer or delete these organizations first: Gina Gray's Workspace.")
        ->and(User::query()->whereKey($gina->id)->exists())->toBeTrue()
        ->and(($this->organizationExists)($workspace))->toBeTrue();
});

test('pending invitations do not count as members', function () {
    $erin = User::factory()->create(['name' => 'Erin Eld']);
    $solo = $erin->createPersonalOrganization();
    OrganizationInvite::factory()->create(['organization_id' => $solo->id, 'invited_by' => $erin->id, 'email' => 'someone@example.com']);

    ($this->deleteAccountAs)($erin)->assertHasNoErrors()->assertRedirect('/');

    expect(($this->organizationExists)($solo))->toBeFalse()
        ->and(OrganizationInvite::query()->where('organization_id', $solo->id)->exists())->toBeFalse();
});

test('an organization where the user is the only member is deleted with the account, a team one included', function () {
    $erin = User::factory()->create(['name' => 'Erin Eld']);
    $erin->createPersonalOrganization();
    $solo = Organization::factory()->create(['name' => 'Solo Shop', 'created_by' => $erin->id]);
    $solo->members()->attach($erin->id, ['role' => OrganizationRole::Owner->value]);
    $client = Contact::factory()->create(['organization_id' => $solo->id, 'name' => 'Shop Client']);

    ($this->deleteAccountAs)($erin)->assertHasNoErrors()->assertRedirect('/');

    expect(($this->organizationExists)($solo))->toBeFalse()
        ->and(Contact::query()->withoutGlobalScope('organization')->whereKey($client->id)->exists())->toBeFalse()
        ->and(DB::table('pipelines')->where('organization_id', $solo->id)->exists())->toBeFalse();
});

test('the password is checked before the organizations', function () {
    $log = fakeSecurityLog();

    $component = ($this->deleteAccountAs)($this->alice, 'wrong-password');

    $component->assertHasErrors(['password'])->assertHasNoErrors(['organizations']);
    expect(securityLogEntries($log))->toBe([])
        ->and(User::query()->whereKey($this->alice->id)->exists())->toBeTrue();
});

test('deleting a user row directly never cascades into shared rows', function () {
    $invite = OrganizationInvite::factory()->create([
        'organization_id' => $this->acme->id,
        'invited_by' => $this->bob->id,
        'email' => 'eve@example.com',
    ]);

    $this->bob->delete();

    expect(($this->organizationExists)($this->acme))->toBeTrue()
        ->and(Deal::query()->withoutGlobalScope('organization')->findOrFail($this->deal->id)->created_by)->toBeNull()
        ->and(Task::query()->withoutGlobalScope('organization')->findOrFail($this->task->id)->created_by)->toBeNull()
        ->and(OrganizationInvite::query()->findOrFail($invite->id)->invited_by)->toBeNull();

    $created = Organization::factory()->create(['created_by' => $this->alice->id]);
    $this->alice->delete();

    expect(Organization::query()->findOrFail($created->id)->created_by)->toBeNull();
});

test('the user database notifications go with the account', function () {
    foreach ([1, 2] as $number) {
        DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Test',
            'notifiable_type' => $this->bob->getMorphClass(),
            'notifiable_id' => $this->bob->id,
            'data' => ['n' => $number],
        ]);
    }
    $other = DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\Test',
        'notifiable_type' => $this->alice->getMorphClass(),
        'notifiable_id' => $this->alice->id,
        'data' => [],
    ]);
    $bobId = $this->bob->id;

    ($this->deleteAccountAs)($this->bob)->assertHasNoErrors();

    expect(DatabaseNotification::query()->where('notifiable_id', $bobId)->count())->toBe(0)
        ->and(DatabaseNotification::query()->whereKey($other->id)->exists())->toBeTrue();
});

test('signing out does not insert the deleted user again', function () {
    $this->bob->forceFill(['remember_token' => Str::random(60)])->save();
    $bobId = $this->bob->id;

    ($this->deleteAccountAs)($this->bob)->assertHasNoErrors();

    expect(User::query()->whereKey($bobId)->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', 'bob@example.com')->exists())->toBeFalse();
});

test('a failure while deleting leaves the account and its organizations untouched', function () {
    $erin = User::factory()->create();
    $solo = $erin->createPersonalOrganization();
    $this->actingAs($erin);

    expect(fn () => app(DeleteUserAccount::class)->handle($erin, function (): void {
        throw new RuntimeException('sign-out failed');
    }))->toThrow(RuntimeException::class);

    expect(User::query()->whereKey($erin->id)->exists())->toBeTrue()
        ->and(($this->organizationExists)($solo))->toBeTrue();
});
