<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Livewire\Tenancy\InvitationsList;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

beforeEach(function () {
    config(['app.debug' => false]);

    $this->log = fakeSecurityLog();
    $this->owen = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'owner-sec63@acme.test']);
    $this->acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $this->owen->id]);
    $this->acme->members()->attach($this->owen->id, ['role' => OrganizationRole::Owner->value]);
    $this->ann = Contact::factory()->create(['organization_id' => $this->acme->id, 'name' => 'Ann Lee']);

    $this->member = function (OrganizationRole $role, string $email): User {
        $user = User::factory()->onboardingCompleted()->create(['email' => $email]);
        $this->acme->members()->attach($user->id, ['role' => $role->value]);

        return $user;
    };

    $this->signInViewer = function (): User {
        $vera = ($this->member)(OrganizationRole::Viewer, 'vera-sec63@acme.test');
        $this->actingAs($vera);
        Filament::setTenant($this->acme);

        return $vera;
    };
});

/**
 * @return list<array{event: string, level: string, context: array<string, mixed>}>
 */
function forbiddenLines(TestHandler $log, string $event = 'request.forbidden'): array
{
    return array_values(array_filter(securityLogEntries($log), fn (array $entry): bool => $entry['event'] === $event));
}

test('a viewer opening the create page writes one forbidden line with the route name', function () {
    $vera = ($this->signInViewer)();

    $this->get(ContactResource::getUrl('create'))->assertForbidden();

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('request.forbidden')
        ->and($entries[0]['level'])->toBe('warning')
        ->and($entries[0]['context'])->toBe([
            'route' => 'filament.admin.resources.contacts.create',
            'user_id' => $vera->id,
            'organization_id' => $this->acme->id,
            'ip' => '127.0.0.1',
        ]);
});

test('a viewer opening the edit page of a contact is logged with the edit route', function () {
    ($this->signInViewer)();

    $this->get(ContactResource::getUrl('edit', ['record' => $this->ann]))->assertForbidden();

    $lines = forbiddenLines($this->log);

    expect(securityLogEntries($this->log))->toHaveCount(1)
        ->and($lines[0]['context']['route'])->toBe('filament.admin.resources.contacts.edit');
});

test('a policy refusal through the can middleware is logged without an organization', function () {
    $max = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['email' => 'max-sec63@example.com']);
    $this->actingAs($max);

    $this->get(route('app.tags.index', $this->acme))->assertForbidden();

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('request.forbidden')
        ->and($entries[0]['context'])->toBe(['route' => 'app.tags.index', 'user_id' => $max->id, 'organization_id' => null, 'ip' => '127.0.0.1']);
});

test('a guest refused by horizon is keyed by IP', function () {
    app()->detectEnvironment(fn (): string => 'production');
    config(['horizon.allowed_user_ids' => []]);

    $this->get('/horizon')->assertForbidden();

    $entries = securityLogEntries($this->log);

    expect($entries)->toHaveCount(1)
        ->and($entries[0]['event'])->toBe('request.forbidden')
        ->and($entries[0]['context'])->toBe(['route' => 'horizon.index', 'user_id' => null, 'organization_id' => null, 'ip' => '127.0.0.1'])
        ->and(RateLimiter::attempts('security-log-refusals:ip:127.0.0.1'))->toBe(1);
});

test('a livewire update refused with 403 is logged with the update route and the user', function () {
    $ada = ($this->member)(OrganizationRole::Admin, 'ada-sec63@acme.test');
    $this->actingAs($ada);
    Filament::setTenant($this->acme);

    $component = Livewire::test(InvitationsList::class);
    $this->acme->members()->updateExistingPivot($ada->id, ['role' => OrganizationRole::Member->value]);
    $component->call('$refresh')->assertForbidden();

    $lines = forbiddenLines($this->log);

    expect(securityLogEntries($this->log))->toHaveCount(1)
        ->and($lines[0]['context']['route'])->toBe('default-livewire.update')
        ->and($lines[0]['context']['user_id'])->toBe($ada->id)
        ->and(array_keys($lines[0]['context']))->toBe(['route', 'user_id', 'organization_id', 'ip']);
});

test('personal data in the query string never reaches the log', function () {
    ($this->signInViewer)();

    $this->get(ContactResource::getUrl('create').'?email=eve%40example.com&q=%3Cscript%3E')->assertForbidden();

    $contexts = array_column(securityLogEntries($this->log), 'context');
    $json = json_encode($contexts, JSON_THROW_ON_ERROR);

    expect($contexts)->toHaveCount(1)
        ->and(array_keys($contexts[0]))->toBe(['route', 'user_id', 'organization_id', 'ip'])
        ->and($json)->not->toContain('eve@example.com')->not->toContain('eve%40example.com')->not->toContain('<script>')->not->toContain('script');
});

test('an unnamed route that aborts with 403 logs a null route', function () {
    ($this->signInViewer)();
    Route::middleware('web')->get('/test-forbidden-unnamed', fn () => abort(403));

    $this->get('/test-forbidden-unnamed')->assertForbidden();

    $lines = forbiddenLines($this->log);

    expect(securityLogEntries($this->log))->toHaveCount(1)
        ->and($lines[0]['context']['route'])->toBeNull();
});

test('a user looping on a forbidden page is capped', function () {
    $vera = ($this->signInViewer)();

    foreach (range(1, 12) as $ignored) {
        $this->get(ContactResource::getUrl('create'))->assertForbidden();
    }

    $capped = forbiddenLines($this->log, 'request.refusals_capped');

    expect(forbiddenLines($this->log))->toHaveCount(10)
        ->and($capped)->toHaveCount(1)
        ->and($capped[0]['context']['cap'])->toBe(10)
        ->and($capped[0]['context']['window_seconds'])->toBe(60)
        ->and(RateLimiter::attempts('security-log-refusals:user:'.$vera->id))->toBe(12);
});

test('the cap is shared with forged payload lines', function () {
    $vera = ($this->signInViewer)();

    foreach (range(1, 10) as $ignored) {
        RateLimiter::hit('security-log-refusals:user:'.$vera->id, 60);
    }

    $this->get(ContactResource::getUrl('create'))->assertForbidden();

    expect(forbiddenLines($this->log))->toBe([])
        ->and(forbiddenLines($this->log, 'request.refusals_capped'))->toHaveCount(1);
});

test('a tampered signed link, a 404 and an allowed page write no forbidden line', function () {
    $vera = ($this->signInViewer)();

    $this->get(route('exports.download', ['file' => 'x.csv', 'user' => $vera->id, 'signature' => 'broken']))->assertForbidden();

    $foreign = Organization::factory()->create(['name' => 'Globex']);
    $other = Contact::factory()->create(['organization_id' => $foreign->id]);
    $this->get(ContactResource::getUrl('edit', ['record' => $other]))->assertNotFound();

    $this->actingAs($this->owen);
    Filament::setTenant($this->acme);
    $this->get(ContactResource::getUrl('create'))->assertSuccessful();

    expect(securityLogEntries($this->log))->toBe([]);
});

test('a forged filament action call by a viewer writes nothing', function () {
    ($this->signInViewer)();

    Livewire::test(ListContacts::class)
        ->call('mountAction', 'create', [], [])
        ->call('callMountedAction');

    expect(Contact::query()->forOrganization($this->acme->id)->count())->toBe(1)
        ->and(forbiddenLines($this->log))->toBe([]);
});

test('a cache failure still logs the line and still answers 403', function () {
    ($this->signInViewer)();
    RateLimiter::shouldReceive('hit')->andThrow(new RuntimeException('cache down'));

    $this->get(ContactResource::getUrl('create'))->assertForbidden();

    expect(forbiddenLines($this->log))->toHaveCount(1);
});
