<?php

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * SEC-27 hostile paths. There is no tenant in these tests: existence checks remove the organization scope.
 */

beforeEach(function () {
    $this->alice = User::factory()->create(['name' => 'Alice Archer', 'email' => 'alice@example.com']);
    $this->alice->createPersonalOrganization();
    $this->acme = Organization::factory()->create(['name' => 'Acme Studio', 'created_by' => $this->alice->id]);
    $this->acme->members()->attach($this->alice->id, ['role' => OrganizationRole::Owner->value]);
    $this->bob = User::factory()->create(['name' => 'Bob Baker', 'email' => 'bob@example.com']);
    $this->bob->createPersonalOrganization();
    $this->acme->members()->attach($this->bob->id, ['role' => OrganizationRole::Member->value]);

    $this->deleteAccountAs = function (User $user) {
        $this->actingAs($user);

        return Livewire::test('pages::settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser');
    };
});

test('a member deleting their account cannot wipe the team pipeline', function () {
    Deal::factory()->count(2)->create(['organization_id' => $this->acme->id, 'created_by' => $this->bob->id]);
    Task::factory()->create(['organization_id' => $this->acme->id, 'created_by' => $this->bob->id]);
    Contact::factory()->count(3)->create(['organization_id' => $this->acme->id]);
    $deals = Deal::query()->withoutGlobalScope('organization')->where('organization_id', $this->acme->id)->count();
    $tasks = Task::query()->withoutGlobalScope('organization')->where('organization_id', $this->acme->id)->count();
    $contacts = Contact::query()->withoutGlobalScope('organization')->where('organization_id', $this->acme->id)->count();

    ($this->deleteAccountAs)($this->bob)->assertHasNoErrors();

    expect(Deal::query()->withoutGlobalScope('organization')->where('organization_id', $this->acme->id)->count())->toBe($deals)
        ->and(Task::query()->withoutGlobalScope('organization')->where('organization_id', $this->acme->id)->count())->toBe($tasks)
        ->and(Contact::query()->withoutGlobalScope('organization')->where('organization_id', $this->acme->id)->count())->toBe($contacts)
        ->and(Deal::query()->withoutGlobalScope('organization')->where('organization_id', $this->acme->id)->whereNotNull('created_by')->count())->toBe(0);
});

test('an invitee joining while the owner deletes the account blocks the deletion', function () {
    $erin = User::factory()->create(['name' => 'Erin Eld']);
    $solo = Organization::factory()->create(['name' => 'Solo Shop', 'created_by' => $erin->id]);
    $solo->members()->attach($erin->id, ['role' => OrganizationRole::Owner->value]);
    $contact = Contact::factory()->create(['organization_id' => $solo->id]);
    $frank = User::factory()->create(['name' => 'Frank Fox']);
    $raced = false;
    $locked = false;

    DB::listen(function (QueryExecuted $query) use (&$raced, &$locked, $solo, $frank): void {
        if ($raced || ! str_contains($query->sql, 'for update') || ! str_contains($query->sql, '"organizations"')) {
            return;
        }

        $raced = true;
        $locked = true;
        DB::table('organization_user')->insert([
            'organization_id' => $solo->id,
            'user_id' => $frank->id,
            'role' => OrganizationRole::Member->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $component = ($this->deleteAccountAs)($erin);

    expect($locked)->toBeTrue()
        ->and($component->errors()->first('organizations'))->toBe('Transfer or delete these organizations first: Solo Shop.')
        ->and(User::query()->whereKey($erin->id)->exists())->toBeTrue()
        ->and(Organization::query()->whereKey($solo->id)->exists())->toBeTrue()
        ->and(Contact::query()->withoutGlobalScope('organization')->whereKey($contact->id)->exists())->toBeTrue();
});

test('the member counts are read after the lock, with a fresh query', function () {
    $erin = User::factory()->create();
    $solo = $erin->createPersonalOrganization();
    $statements = [];

    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    ($this->deleteAccountAs)($erin)->assertHasNoErrors();

    $lockAt = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'for update'));
    $countAt = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'count(*)') && str_contains($sql, '"organization_user"'));

    expect($lockAt)->not->toBeFalse()
        ->and($countAt)->not->toBeFalse()
        ->and($countAt)->toBeGreaterThan($lockAt)
        ->and(Organization::query()->whereKey($solo->id)->exists())->toBeFalse();
});

test('a refusal is logged without organization names and writes no deletion event', function () {
    $zeta = Organization::factory()->create(['name' => 'Zeta Labs', 'created_by' => $this->alice->id]);
    $zeta->members()->attach($this->alice->id, ['role' => OrganizationRole::Owner->value]);
    $zeta->members()->attach($this->bob->id, ['role' => OrganizationRole::Member->value]);
    $log = fakeSecurityLog();

    ($this->deleteAccountAs)($this->alice)->assertHasErrors(['organizations']);

    $entries = securityLogEntries($log);

    expect(array_column($entries, 'event'))->toBe(['account.deletion_refused'])
        ->and($entries[0]['level'])->toBe('info')
        ->and($entries[0]['context'])->toMatchArray(['user_id' => $this->alice->id, 'organizations' => 2])
        ->and(json_encode($entries))->not->toContain('Acme Studio')->not->toContain('Zeta Labs');
});

test('a successful deletion is logged with counts and no names, before the sign-out', function () {
    $log = fakeSecurityLog();

    ($this->deleteAccountAs)($this->bob)->assertHasNoErrors();

    $entries = securityLogEntries($log);

    expect(array_column($entries, 'event'))->toBe(['account.deleted', 'auth.logout'])
        ->and($entries[0]['level'])->toBe('warning')
        ->and($entries[0]['context'])->toMatchArray(['deleted_organizations' => 1, 'left_organizations' => 1])
        ->and(json_encode($entries))->not->toContain('Acme Studio')->not->toContain('bob@example.com');
});

test('an invitation from a deleted user still opens, can be accepted and its mail renders', function () {
    $invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'organization_id' => $this->acme->id,
        'invited_by' => $this->bob->id,
        'email' => 'eve@example.com',
        'role' => OrganizationRole::Member,
    ]);
    $eve = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'eve@example.com']);

    ($this->deleteAccountAs)($this->bob)->assertHasNoErrors();

    expect(OrganizationInvite::query()->findOrFail($invite->id)->invited_by)->toBeNull();

    auth()->logout();
    $this->get(route('invite.accept', $token))->assertRedirect();

    $this->actingAs($eve)
        ->get(route('invite.accept', $token))
        ->assertOk()
        ->assertSee('You have been invited to join Acme Studio as Member.')
        ->assertDontSee('invited you to join');

    $this->actingAs($eve)->post(route('invite.accept.store', $token));

    expect($this->acme->members()->whereKey($eve->id)->exists())->toBeTrue();

    $html = (new OrganizationInvitationMail($invite->fresh(), $token))->render();

    expect($html)->toContain('You have been invited to join')->toContain('Acme Studio')->not->toContain('has invited you');
});
