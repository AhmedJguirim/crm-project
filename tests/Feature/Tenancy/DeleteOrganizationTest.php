<?php

use App\Actions\Organization\DeleteOrganization;
use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\EditOrganization;
use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\User;
use App\Support\Tenancy\InvitationLimits;
use App\Support\UserFiles;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * SEC-51: the owner deletes an organization (soft delete, purged 30 days later).
 */

beforeEach(function () {
    Storage::fake('local');

    $this->olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Olga Owner', 'email' => 'olga@example.com']);
    $this->adam = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'adam@example.com']);
    $this->mia = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'mia@example.com']);

    $this->acme = Organization::factory()->create(['name' => 'Acme Studio', 'created_by' => $this->olga->id]);
    $this->acme->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);
    $this->acme->members()->attach($this->adam->id, ['role' => OrganizationRole::Admin->value]);
    $this->acme->members()->attach($this->mia->id, ['role' => OrganizationRole::Member->value]);

    $this->globex = Organization::factory()->create(['name' => 'Globex', 'created_by' => $this->olga->id]);
    $this->globex->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);

    $this->tables = seedOrganizationRows($this->acme);
    $this->rowsBefore = organizationRowCounts($this->acme, $this->tables);

    $this->invite = OrganizationInvite::factory()->withToken($token = Str::random(64))->create([
        'organization_id' => $this->acme->id,
        'email' => 'new@example.com',
        'status' => InviteStatus::Pending,
    ]);
    $this->inviteToken = $token;

    $this->put = function (string $path): string {
        Storage::disk('local')->put($path, 'x');

        return $path;
    };
    $this->acmeExport = ($this->put)(UserFiles::exportPath('contacts', $this->acme->id, $this->olga->id, 'csv'));
    $this->acmeFailed = ($this->put)(UserFiles::failedRowsPath($this->acme->id, $this->mia->id));
    $this->globexExport = ($this->put)(UserFiles::exportPath('contacts', $this->globex->id, $this->olga->id, 'csv'));

    $this->asOlgaOn = function (Organization $organization) {
        $this->actingAs($this->olga);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($organization);

        return Livewire::test(EditOrganization::class);
    };
});

test('the owner deletes the organization: soft deleted, rows kept, invitations revoked, files gone, members told, logged', function () {
    $log = fakeSecurityLog();

    ($this->asOlgaOn)($this->acme)
        ->callAction('deleteOrganization', ['confirmation' => 'Acme Studio'])
        ->assertHasNoActionErrors()
        ->assertNotified('Acme Studio was deleted')
        ->assertRedirect(Filament::getUrl($this->olga->personalOrganization()));

    expect(Organization::query()->whereKey($this->acme->id)->exists())->toBeFalse()
        ->and(Organization::onlyTrashed()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and(organizationRowCounts($this->acme, $this->tables))->toBe($this->rowsBefore)
        ->and($this->invite->fresh()->status)->toBe(InviteStatus::Revoked)
        ->and(Storage::disk('local')->exists($this->acmeExport))->toBeFalse()
        ->and(Storage::disk('local')->exists($this->acmeFailed))->toBeFalse()
        ->and(Storage::disk('local')->exists($this->globexExport))->toBeTrue()
        ->and($this->olga->notifications()->count())->toBe(0);

    foreach ([$this->adam, $this->mia] as $member) {
        $notifications = $member->notifications()->get();

        expect($notifications)->toHaveCount(1)
            ->and($notifications->first()->data['title'])->toBe('Acme Studio was deleted')
            ->and($notifications->first()->data['body'])->toBe('Olga Owner deleted this organization. You no longer have access to it.');
    }

    $entries = securityLogEntries($log);

    expect(array_column($entries, 'event'))->toContain('organization.deleted')
        ->and(collect($entries)->firstWhere('event', 'organization.deleted')['level'])->toBe('warning')
        ->and(collect($entries)->firstWhere('event', 'organization.deleted')['context'])->toMatchArray([
            'deleted_organization_id' => $this->acme->id,
            'members' => 2,
        ]);
});

test('the success notification tells the grace period', function () {
    ($this->asOlgaOn)($this->acme)
        ->callAction('deleteOrganization', ['confirmation' => 'Acme Studio'])
        ->assertNotified(
            Notification::make()
                ->success()
                ->title('Acme Studio was deleted')
                ->body('It will be removed for good in 30 days.')
        );
});

test('members lose access at once', function () {
    ($this->asOlgaOn)($this->acme)->callAction('deleteOrganization', ['confirmation' => 'Acme Studio']);

    $url = ContactResource::getUrl('index', tenant: $this->acme);

    $mia = $this->mia->fresh();
    $this->actingAs($mia);

    expect($mia->getTenants(Filament::getPanel('admin'))->pluck('id')->all())->not->toContain($this->acme->id)
        ->and($mia->roleIn($this->acme->id))->toBeNull()
        ->and($mia->canAccessTenant($this->acme))->toBeFalse();

    $this->get($url)->assertNotFound();
});

test('a wrong confirmation changes nothing', function () {
    $page = ($this->asOlgaOn)($this->acme)
        ->callAction('deleteOrganization', ['confirmation' => 'acme studio']);

    expect($page->errors()->get('mountedActions.0.data.confirmation'))->toContain('The organization name does not match.')
        ->and(Organization::query()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and($this->invite->fresh()->status)->toBe(InviteStatus::Pending)
        ->and(Storage::disk('local')->exists($this->acmeExport))->toBeTrue()
        ->and($this->adam->notifications()->count())->toBe(0);
});

test('the action is hidden on a personal workspace', function () {
    ($this->asOlgaOn)($this->olga->personalOrganization())
        ->assertActionHidden('deleteOrganization');
});

test('the action is visible on a non personal organization', function () {
    ($this->asOlgaOn)($this->acme)->assertActionVisible('deleteOrganization');
});

test('a personal workspace cannot be deleted even by calling the action directly', function () {
    $personal = $this->olga->personalOrganization();

    $refusal = app(DeleteOrganization::class)->handle($this->olga, $personal);

    expect($refusal)->toBe('Only the owner can delete this organization.')
        ->and(Organization::query()->whereKey($personal->id)->exists())->toBeTrue();
});

test('the invitation link of a deleted organization is refused with the revoked message, on GET and POST', function () {
    ($this->asOlgaOn)($this->acme)->callAction('deleteOrganization', ['confirmation' => 'Acme Studio']);

    $this->get(route('invite.accept', $this->inviteToken))->assertRedirect(Filament::getLoginUrl());
    expect(json_encode(session('filament.notifications')))->toContain('This invitation has been revoked. Please contact the organization admin for a new invite.');

    session()->forget('filament.notifications');
    $this->actingAs($this->mia)->post(route('invite.accept.store', $this->inviteToken))->assertRedirect(Filament::getLoginUrl());
    expect(json_encode(session('filament.notifications')))->toContain('This invitation has been revoked. Please contact the organization admin for a new invite.');
});

test('a pending invitation of a trashed organization that escaped the revoke gets the same message, not a 500', function () {
    $this->acme->delete();
    DB::table('organization_invites')->where('id', $this->invite->id)->update(['status' => InviteStatus::Pending->value]);

    $this->get(route('invite.accept', $this->inviteToken))->assertRedirect(Filament::getLoginUrl());
    expect(json_encode(session('filament.notifications')))->toContain('This invitation has been revoked');

    session()->forget('filament.notifications');
    $this->actingAs(User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'new@example.com']))
        ->post(route('invite.accept.store', $this->inviteToken))
        ->assertRedirect(Filament::getLoginUrl());

    expect(json_encode(session('filament.notifications')))->toContain('This invitation has been revoked')
        ->and(DB::table('organization_user')->where('organization_id', $this->acme->id)->count())->toBe(3);
});

test('an organization in its grace period still counts toward the organization cap', function () {
    $this->actingAs($this->olga);

    expect(Organization::query()->where('created_by', $this->olga->id)->where('personal_team', false)->count())->toBe(2);

    Organization::factory()->count(8)->create(['created_by' => $this->olga->id, 'personal_team' => false]);
    Organization::query()->where('created_by', $this->olga->id)->where('personal_team', false)->first()->delete();

    expect(InvitationLimits::organizationLimitReached($this->olga))->toBeTrue();
});

test('only the files of this organization id go, not those of an id that starts with the same digits or a malformed name', function () {
    $longer = ($this->put)('exports/contacts-o'.$this->acme->id.'0-u'.$this->olga->id.'-'.str_repeat('a', 40).'.csv');
    $malformed = ($this->put)('exports/contacts-o'.$this->acme->id.'-u'.$this->olga->id.'-short.csv');
    $nested = ($this->put)('exports/sub/contacts-o'.$this->acme->id.'-u'.$this->olga->id.'-'.str_repeat('b', 40).'.csv');

    ($this->asOlgaOn)($this->acme)->callAction('deleteOrganization', ['confirmation' => 'Acme Studio']);

    expect(Storage::disk('local')->exists($longer))->toBeTrue()
        ->and(Storage::disk('local')->exists($malformed))->toBeTrue()
        ->and(Storage::disk('local')->exists($nested))->toBeTrue()
        ->and(Storage::disk('local')->exists($this->acmeExport))->toBeFalse();
});
