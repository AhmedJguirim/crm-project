<?php

use App\Actions\Organization\DeleteOrganization;
use App\Actions\Organization\RestoreOrganization;
use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * SEC-52: hostile scenarios for organizations:restore.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Olga Owner']);
    $this->adam = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->acme = Organization::factory()->create(['name' => 'Acme Studio', 'created_by' => $this->olga->id]);
    $this->acme->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);
    $this->acme->members()->attach($this->adam->id, ['role' => OrganizationRole::Admin->value]);

    $this->travelTo('2026-11-01 09:00:00');
    expect(app(DeleteOrganization::class)->handle($this->olga, $this->acme))->toBeNull();
    $this->travelTo('2026-11-10 12:00:00');

    $this->restoredCount = fn (User $user): int => $user->notifications()->get()->where('data.title', 'Acme Studio was restored')->count();
});

it('restoring twice writes nothing the second time', function () {
    $log = fakeSecurityLog();

    $this->artisan("organizations:restore {$this->acme->id} --force")->assertSuccessful();

    $this->artisan("organizations:restore {$this->acme->id} --force")
        ->expectsOutputToContain("Organization #{$this->acme->id} \"Acme Studio\" is not deleted: there is nothing to restore.")
        ->assertExitCode(1);

    expect(($this->restoredCount)($this->olga))->toBe(1)
        ->and(($this->restoredCount)($this->adam))->toBe(1)
        ->and(collect(securityLogEntries($log))->where('event', 'organization.restored'))->toHaveCount(1);
});

it('re-checks under the lock after the pre-check passed', function () {
    $action = app(RestoreOrganization::class);
    $log = fakeSecurityLog();

    expect($action->refusalFor($this->acme->id, Organization::withTrashed()->find($this->acme->id)))->toBeNull();

    $this->acme->forceDelete();

    expect($action->handle($this->acme->id))->toBe("No organization #{$this->acme->id} exists: it was never created or has already been purged.")
        ->and(Organization::withTrashed()->whereKey($this->acme->id)->exists())->toBeFalse()
        ->and(($this->restoredCount)($this->adam))->toBe(0)
        ->and(collect(securityLogEntries($log))->where('event', 'organization.restored'))->toBeEmpty();
});

it('re-checks the owner under the lock after the pre-check passed', function () {
    $action = app(RestoreOrganization::class);

    expect($action->refusalFor($this->acme->id, Organization::withTrashed()->find($this->acme->id)))->toBeNull();

    DB::table('organization_user')->where('organization_id', $this->acme->id)->where('user_id', $this->olga->id)->delete();

    expect($action->handle($this->acme->id))->toContain('has no owner any more')
        ->and(Organization::onlyTrashed()->whereKey($this->acme->id)->exists())->toBeTrue()
        ->and(($this->restoredCount)($this->adam))->toBe(0);
});

it('prints an organization name with console tags and control characters as literal text', function () {
    $name = "<href=https://evil.test>Acme</>\e[2J";
    Organization::withTrashed()->whereKey($this->acme->id)->update(['name' => $name]);
    $escaped = '\\<href=https://evil.test\\>Acme\\</\\>[2J';

    $this->artisan("organizations:restore {$this->acme->id}")
        ->expectsConfirmation("Restore organization #{$this->acme->id} \"{$escaped}\", deleted on 2026-11-01 09:00 UTC, with 2 members?", 'yes')
        ->expectsOutputToContain("Restored organization #{$this->acme->id} \"<href=https://evil.test>Acme</>[2J\". 2 members were notified.")
        ->assertSuccessful();

    expect(Organization::query()->find($this->acme->id)->name)->toBe($name);
});

it('prints no escape character and no applied tag when the hostile name is in a refusal or a success line', function () {
    $name = "<href=https://evil.test>Acme</>\e[2J";
    Organization::withTrashed()->whereKey($this->acme->id)->update(['name' => $name]);
    $output = new BufferedOutput(decorated: true);

    Artisan::call("organizations:restore {$this->acme->id} --force", [], $output);
    $success = $output->fetch();
    Artisan::call("organizations:restore {$this->acme->id} --force", [], $output);
    $refusal = $output->fetch();

    foreach ([$success, $refusal] as $text) {
        expect($text)->not->toContain("\e[2J")
            ->and($text)->toContain('<href=https://evil.test>Acme</>[2J')
            ->and($text)->not->toContain("\e]8;");
    }

    expect($refusal)->toContain('is not deleted: there is nothing to restore.');
});
