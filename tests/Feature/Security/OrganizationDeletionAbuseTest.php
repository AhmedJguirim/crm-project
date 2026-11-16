<?php

use App\Actions\Organization\DeleteOrganization;
use App\Enums\InviteStatus;
use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\EditOrganization;
use App\Jobs\SyncSegmentMembership;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Support\Tenancy\InvitationLimits;
use App\Support\UserFiles;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
 * SEC-51: abuse of organization deletion (non-owners, a stale modal, a second submit, links after the deletion, the cap).
 */

beforeEach(function () {
    Storage::fake('local');

    $this->olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Olga Owner']);
    $this->adam = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->mia = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->vic = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->acme = Organization::factory()->create(['name' => 'Acme Studio', 'created_by' => $this->olga->id]);
    $this->acme->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);
    $this->acme->members()->attach($this->adam->id, ['role' => OrganizationRole::Admin->value]);
    $this->acme->members()->attach($this->mia->id, ['role' => OrganizationRole::Member->value]);
    $this->acme->members()->attach($this->vic->id, ['role' => OrganizationRole::Viewer->value]);

    $this->invite = OrganizationInvite::factory()->create(['organization_id' => $this->acme->id, 'status' => InviteStatus::Pending]);
});

test('a non-owner cannot reach the settings page, so cannot delete', function (string $who) {
    $this->actingAs($this->{$who});
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->get(EditOrganization::getUrl(tenant: $this->acme))->assertNotFound();

    expect(Organization::query()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and($this->invite->fresh()->status)->toBe(InviteStatus::Pending);
})->with(['admin' => 'adam', 'member' => 'mia', 'viewer' => 'vic']);

test('the action itself refuses a non-owner under the lock', function (string $who) {
    $log = fakeSecurityLog();

    $refusal = app(DeleteOrganization::class)->handle($this->{$who}, $this->acme);

    expect($refusal)->toBe('Only the owner can delete this organization.')
        ->and(Organization::query()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and($this->invite->fresh()->status)->toBe(InviteStatus::Pending)
        ->and(array_column(securityLogEntries($log), 'event'))->not->toContain('organization.deleted');
})->with(['admin' => 'adam', 'member' => 'mia', 'viewer' => 'vic']);

test('an owner who handed the organization over in another tab is refused', function () {
    $stale = $this->acme->fresh();
    $this->acme->members()->updateExistingPivot($this->olga->id, ['role' => OrganizationRole::Admin->value]);
    $this->acme->members()->updateExistingPivot($this->adam->id, ['role' => OrganizationRole::Owner->value]);
    $log = fakeSecurityLog();

    $refusal = app(DeleteOrganization::class)->handle($this->olga, $stale);

    expect($refusal)->toBe('Only the owner can delete this organization.')
        ->and(Organization::query()->whereKey($this->acme->id)->whereNull('deleted_at')->exists())->toBeTrue()
        ->and($this->invite->fresh()->status)->toBe(InviteStatus::Pending)
        ->and($this->mia->notifications()->count())->toBe(0)
        ->and(array_column(securityLogEntries($log), 'event'))->not->toContain('organization.deleted');
});

test('deleting twice is refused and writes nothing the second time', function () {
    $stale = $this->acme->fresh();
    $action = app(DeleteOrganization::class);

    expect($action->handle($this->olga, $stale))->toBeNull();

    $log = fakeSecurityLog();

    expect($action->handle($this->olga, $stale))->toBe('This organization was already deleted.')
        ->and($this->mia->notifications()->count())->toBe(1)
        ->and(securityLogEntries($log))->toBe([]);
});

test('a member\'s late export file cannot be downloaded after the deletion', function () {
    $path = UserFiles::exportPath('contacts', $this->acme->id, $this->adam->id, 'csv');
    $file = basename($path);

    expect(app(DeleteOrganization::class)->handle($this->olga, $this->acme))->toBeNull();

    Storage::disk('local')->put($path, 'secret rows');

    $url = URL::temporarySignedRoute('exports.download', now()->addDays(7), [
        'file' => $file,
        'user' => $this->adam->id,
        'organization' => $this->acme->id,
    ]);

    $this->actingAs($this->adam->fresh())->get($url)->assertForbidden();
});

test('the same link works while the organization is not deleted (control)', function () {
    $path = UserFiles::exportPath('contacts', $this->acme->id, $this->adam->id, 'csv');
    Storage::disk('local')->put($path, 'rows');

    $url = URL::temporarySignedRoute('exports.download', now()->addDays(7), [
        'file' => basename($path),
        'user' => $this->adam->id,
        'organization' => $this->acme->id,
    ]);

    $this->actingAs($this->adam)->get($url)->assertOk();
});

test('creating and deleting organizations does not get round the cap of 10', function () {
    Organization::factory()->count(8)->create(['created_by' => $this->olga->id, 'personal_team' => false]);

    expect(Organization::query()->where('created_by', $this->olga->id)->where('personal_team', false)->count())->toBe(9);

    $extra = Organization::factory()->create(['created_by' => $this->olga->id, 'personal_team' => false]);
    $extra->delete();

    expect(InvitationLimits::organizationLimitReached($this->olga))->toBeTrue();
});

test('the invoice PDF link of a deleted organization answers 403 for a former member and a stranger, never 500', function () {
    $invoice = Invoice::factory()->create(['organization_id' => $this->acme->id, 'contact_id' => Contact::factory()->create(['organization_id' => $this->acme->id])->id]);
    $stranger = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->actingAs($this->mia)->get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('content-type', 'application/pdf');

    expect(app(DeleteOrganization::class)->handle($this->olga, $this->acme))->toBeNull();

    $this->actingAs($this->mia->fresh())->get(route('invoices.pdf', $invoice))->assertForbidden();
    $this->actingAs($stranger)->get(route('invoices.pdf', $invoice))->assertForbidden();
});

test('the tag page of a deleted organization answers 404, never 500', function () {
    $tag = Tag::factory()->for($this->acme)->create();

    $this->actingAs($this->mia)->get(route('app.tags.show', $tag))->assertOk();

    expect(app(DeleteOrganization::class)->handle($this->olga, $this->acme))->toBeNull();

    $this->actingAs($this->mia->fresh())->get(route('app.tags.show', $tag))->assertNotFound();
});

test('a segment sync that finishes after the organization was deleted sends no notification and does not crash', function () {
    $segment = Segment::factory()->published()->create(['organization_id' => $this->acme->id]);

    expect(app(DeleteOrganization::class)->handle($this->olga, $this->acme))->toBeNull();

    SyncSegmentMembership::dispatchSync($segment->id, $this->mia->id);

    expect($this->mia->notifications()->where('data->title', 'Segment setup complete')->count())->toBe(0);
});
