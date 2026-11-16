<?php

use App\Enums\OrganizationRole;
use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Jobs\ExportContactsJob;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use App\Support\UserFiles;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * SEC-49: the export and failed-rows files of a user go when they leave an organization, are removed from it or delete
 * their account.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $this->olga->id]);
    $this->acme->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);
    $this->acme->members()->attach($this->ann->id, ['role' => OrganizationRole::Admin->value]);
    $this->acme->members()->attach($this->bob->id, ['role' => OrganizationRole::Admin->value]);

    $this->put = function (string $path): string {
        Storage::disk('local')->put($path, 'x');

        return $path;
    };

    $this->files = [
        'annExport' => ($this->put)(UserFiles::exportPath('contacts', $this->acme->id, $this->ann->id, 'xlsx')),
        'annFailed' => ($this->put)(UserFiles::failedRowsPath($this->acme->id, $this->ann->id)),
        'annPersonal' => ($this->put)(UserFiles::exportPath('deals', $this->ann->personalOrganization()->id, $this->ann->id, 'csv')),
        'bobExport' => ($this->put)(UserFiles::exportPath('contacts', $this->acme->id, $this->bob->id, 'xlsx')),
        'olgaFailed' => ($this->put)(UserFiles::failedRowsPath($this->acme->id, $this->olga->id)),
    ];

    $this->exists = fn (string ...$keys): array => array_map(
        fn (string $key): bool => Storage::disk('local')->exists($this->files[$key]),
        $keys,
    );
});

test('leaving deletes only the leaver\'s files in that organization', function () {
    $this->actingAs($this->ann);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->acme);

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'Acme')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    expect($this->acme->members()->whereKey($this->ann->id)->exists())->toBeFalse()
        ->and(($this->exists)('annExport', 'annFailed'))->toBe([false, false])
        ->and(($this->exists)('annPersonal', 'bobExport', 'olgaFailed'))->toBe([true, true, true]);
});

test('a refused leave (wrong confirmation) deletes nothing', function () {
    $this->actingAs($this->ann);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->acme);

    Livewire::test(Topbar::class)
        ->call('mountAction', 'leaveOrganization')
        ->set('mountedActions.0.data.confirmation', 'Wrong')
        ->call('callMountedAction')
        ->assertHasErrors();

    expect($this->acme->members()->whereKey($this->ann->id)->exists())->toBeTrue()
        ->and(($this->exists)('annExport', 'annFailed', 'annPersonal', 'bobExport', 'olgaFailed'))->toBe([true, true, true, true, true]);
});

test('removing a member deletes that member\'s files in that organization', function () {
    $this->actingAs($this->olga);
    Filament::setTenant($this->acme);

    $pivot = OrganizationUser::query()->where('organization_id', $this->acme->id)->where('user_id', $this->ann->id)->sole();

    Livewire::test(OrganizationMembers::class)
        ->callAction(TestAction::make('removeMember')->table($pivot))
        ->assertNotified('Member removed');

    expect($this->acme->members()->whereKey($this->ann->id)->exists())->toBeFalse()
        ->and(($this->exists)('annExport', 'annFailed'))->toBe([false, false])
        ->and(($this->exists)('annPersonal', 'bobExport', 'olgaFailed'))->toBe([true, true, true]);
});

test('the owner cannot be removed and keeps their files', function () {
    $this->actingAs($this->ann);
    Filament::setTenant($this->acme);

    $pivot = OrganizationUser::query()->where('organization_id', $this->acme->id)->where('user_id', $this->olga->id)->sole();

    Livewire::test(OrganizationMembers::class)
        ->assertActionHidden(TestAction::make('removeMember')->table($pivot));

    expect($this->acme->members()->whereKey($this->olga->id)->exists())->toBeTrue()
        ->and(($this->exists)('olgaFailed', 'annExport', 'bobExport'))->toBe([true, true, true]);
});

test('deleting the account deletes the user\'s files in every organization', function () {
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect(User::query()->whereKey($this->ann->id)->exists())->toBeFalse()
        ->and(($this->exists)('annExport', 'annFailed', 'annPersonal'))->toBe([false, false, false])
        ->and(($this->exists)('bobExport', 'olgaFailed'))->toBe([true, true]);
});

test('a refused account deletion deletes nothing', function () {
    $this->actingAs($this->olga);

    $component = Livewire::test('pages::settings.delete-user-form')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['organizations']);

    expect($component->errors()->first('organizations'))->toBe('Transfer or delete these organizations first: Acme.')
        ->and(User::query()->whereKey($this->olga->id)->exists())->toBeTrue()
        ->and(($this->exists)('annExport', 'annFailed', 'annPersonal', 'bobExport', 'olgaFailed'))->toBe([true, true, true, true, true]);
});

test('a wrong password deletes nothing', function () {
    $this->actingAs($this->ann);

    Livewire::test('pages::settings.delete-user-form')
        ->set('password', 'not-the-password')
        ->call('deleteUser')
        ->assertHasErrors();

    expect(($this->exists)('annExport', 'annFailed', 'annPersonal'))->toBe([true, true, true]);
});

test('the user ids must match exactly', function () {
    $other = Organization::factory()->create();
    $two = ($this->put)('exports/contacts-o'.$other->id.'-u2-'.str_repeat('a', 40).'.xlsx');
    $twentyThree = ($this->put)('exports/contacts-o'.$other->id.'-u23-'.str_repeat('b', 40).'.xlsx');
    $twelve = ($this->put)('exports/contacts-o'.$other->id.'-u12-'.str_repeat('c', 40).'.xlsx');

    expect(UserFiles::deleteForMember(2, $other->id))->toBe(1)
        ->and(Storage::disk('local')->exists($two))->toBeFalse()
        ->and(Storage::disk('local')->exists($twentyThree))->toBeTrue()
        ->and(Storage::disk('local')->exists($twelve))->toBeTrue();
});

test('the organization ids must match exactly', function () {
    $one = ($this->put)('exports/contacts-o1-u9-'.str_repeat('a', 40).'.xlsx');
    $oneHundred = ($this->put)('exports/contacts-o100-u9-'.str_repeat('b', 40).'.xlsx');
    $failedOne = ($this->put)('contact-imports/failed-o1-u9-'.str_repeat('c', 40).'.csv');

    expect(UserFiles::deleteForMember(9, 1))->toBe(2)
        ->and(Storage::disk('local')->exists($one))->toBeFalse()
        ->and(Storage::disk('local')->exists($failedOne))->toBeFalse()
        ->and(Storage::disk('local')->exists($oneHundred))->toBeTrue();
});

test('deleting for a user covers every organization, both folders, and twice is a no-op', function () {
    expect(UserFiles::deleteForUser($this->ann->id))->toBe(3)
        ->and(UserFiles::deleteForUser($this->ann->id))->toBe(0)
        ->and(UserFiles::deleteForMember($this->ann->id, $this->acme->id))->toBe(0)
        ->and(($this->exists)('bobExport', 'olgaFailed'))->toBe([true, true]);
});

test('files that only look like a member file are left alone', function (string $path) {
    ($this->put)($path);

    expect(UserFiles::deleteForUser(7))->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
})->with([
    'old name without ids' => 'exports/contacts-'.str_repeat('a', 40).'.xlsx',
    'one character too short' => 'exports/contacts-o3-u7-'.str_repeat('a', 39).'.xlsx',
    'one character too long' => 'exports/contacts-o3-u7-'.str_repeat('a', 41).'.xlsx',
    'wrong extension' => 'exports/contacts-o3-u7-'.str_repeat('a', 40).'.pdf',
    'organization zero' => 'exports/contacts-o0-u7-'.str_repeat('a', 40).'.xlsx',
    'an export name in the imports folder' => 'contact-imports/contacts-o3-u7-'.str_repeat('a', 40).'.xlsx',
    'another folder' => 'organization-logos/contacts-o3-u7-'.str_repeat('a', 40).'.xlsx',
    'a nested folder' => 'exports/sub/contacts-o3-u7-'.str_repeat('a', 40).'.xlsx',
]);

test('an export that finishes after its user was deleted leaves no file and no notification', function () {
    $ghost = User::factory()->onboardingCompleted()->create();
    $ghostId = $ghost->id;
    $ghost->delete();

    ExportContactsJob::dispatchSync([], $this->acme->id, $ghostId, 'xlsx', 'contacts-2026-10-06.xlsx');

    expect(collect(Storage::disk('local')->files('exports'))->filter(fn (string $file): bool => str_contains($file, "-u{$ghostId}-"))->all())->toBe([])
        ->and(DatabaseNotification::query()->count())->toBe(0);
});

test('an export of a user who still exists keeps its file and names both ids', function () {
    ExportContactsJob::dispatchSync([], $this->acme->id, $this->ann->id, 'xlsx', 'contacts-2026-10-06.xlsx');

    $names = collect(Storage::disk('local')->files('exports'))->map(fn (string $file): string => basename($file))
        ->filter(fn (string $name): bool => str_starts_with($name, 'contacts-o'.$this->acme->id.'-u'.$this->ann->id.'-'))
        ->all();

    expect($names)->toHaveCount(2)
        ->and(DatabaseNotification::query()->where('notifiable_id', $this->ann->id)->count())->toBe(1);
});

test('the name patterns accept the names written by the paths and nothing around them', function () {
    $export = basename(UserFiles::exportPath('contacts', 12, 34, 'xlsx'));
    $failed = basename(UserFiles::failedRowsPath(12, 34));

    expect($export)->toMatch(UserFiles::EXPORT_NAME_PATTERN)
        ->and($failed)->toMatch(UserFiles::FAILED_ROWS_NAME_PATTERN)
        ->and(preg_match(UserFiles::EXPORT_NAME_PATTERN, $export."\n"))->toBe(0)
        ->and(preg_match(UserFiles::FAILED_ROWS_NAME_PATTERN, $failed."\n"))->toBe(0)
        ->and(preg_match(UserFiles::FAILED_ROWS_NAME_PATTERN, $export))->toBe(0);
});
