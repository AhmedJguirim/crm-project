<?php

use App\Enums\OrganizationRole;
use App\Http\Controllers\DownloadExportController;
use App\Jobs\Concerns\DeliversExport;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');

    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->stored = function (string $extension = 'xlsx', string $contents = 'file contents', string $prefix = 'contacts'): string {
        $file = "{$prefix}-".Str::random(40).".{$extension}";
        Storage::disk('local')->put("exports/{$file}", $contents);

        return $file;
    };

    $this->link = fn (array $query, ?int $userId = null): string => URL::temporarySignedRoute(
        'exports.download',
        now()->addDays(7),
        ['user' => $userId ?? $this->ann->id, 'organization' => $this->ann->personalOrganization()->id, ...$query],
    );
});

it('lets the user download the file under the name given in the link', function () {
    $file = ($this->stored)('xlsx', 'xlsx bytes');

    $response = $this->actingAs($this->ann)->get(($this->link)(['file' => $file, 'name' => 'contacts-2026-10-06.xlsx']));

    $response->assertOk()
        ->assertDownload('contacts-2026-10-06.xlsx')
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->streamedContent())->toBe('xlsx bytes');
});

it('lets the user download a companies file under the name given in the link', function () {
    $file = ($this->stored)('xlsx', 'companies bytes', 'companies');

    $response = $this->actingAs($this->ann)->get(($this->link)(['file' => $file, 'name' => 'companies-2026-10-07.xlsx']));

    $response->assertOk()
        ->assertDownload('companies-2026-10-07.xlsx')
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->streamedContent())->toBe('companies bytes');

    $this->actingAs($this->bob)->get(($this->link)(['file' => $file, 'name' => 'companies-2026-10-07.xlsx']))->assertForbidden();
});

it('lets the owner download a deals file under the name given in the link and refuses someone else', function () {
    $file = ($this->stored)('xlsx', 'deals bytes', 'deals');

    $response = $this->actingAs($this->ann)->get(($this->link)(['file' => $file, 'name' => 'deals-2026-11-10.xlsx']));

    $response->assertOk()->assertDownload('deals-2026-11-10.xlsx');
    expect($response->streamedContent())->toBe('deals bytes');

    $this->actingAs($this->bob)->get(($this->link)(['file' => $file, 'name' => 'deals-2026-11-10.xlsx']))->assertForbidden();
});

it('serves a csv as text/csv', function () {
    $file = ($this->stored)('csv');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file, 'name' => 'contacts.csv']))
        ->assertOk()
        ->assertDownload('contacts.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

it('uses the stored file name when the name in the link is not a plain file name', function (string $name) {
    $file = ($this->stored)('csv');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file, 'name' => $name]))
        ->assertOk()
        ->assertDownload($file);
})->with(['a path' => '../../.env', 'a space' => 'my contacts.csv', 'too long' => str_repeat('a', 101).'.csv', 'empty' => '']);

it('uses the stored file name when the link has no name', function () {
    $file = ($this->stored)('csv');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file]))->assertDownload($file);
});

it('refuses the link to someone else, or one whose user was changed', function () {
    $file = ($this->stored)();
    $url = ($this->link)(['file' => $file]);

    $this->actingAs($this->bob)->get($url)->assertForbidden();
    $this->actingAs($this->bob)->get(preg_replace('/user=\d+/', 'user='.$this->bob->id, $url))->assertForbidden();
});

it('refuses an unsigned or expired link', function () {
    $file = ($this->stored)();

    $this->actingAs($this->ann)->get(route('exports.download', ['file' => $file, 'user' => $this->ann->id]))->assertForbidden();

    $url = ($this->link)(['file' => $file]);
    $this->travel(8)->days();

    $this->actingAs($this->ann)->get($url)->assertForbidden();
});

it('sends a guest to the login page', function () {
    $file = ($this->stored)();

    $this->get(($this->link)(['file' => $file]))->assertRedirect();
});

it('only accepts the file names of exports', function (string $file) {
    Storage::disk('local')->put('livewire-tmp/secret.csv', 'secret');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file]))->assertNotFound();
})->with([
    'a path' => '../.env',
    'a path to another folder' => '../livewire-tmp/secret.csv',
    'too short' => 'contacts-short.xlsx',
    'wrong extension' => 'contacts-'.str_repeat('a', 40).'.pdf',
    'wrong prefix' => 'invoices-'.str_repeat('a', 40).'.xlsx',
    'a failed rows file' => 'failed-'.str_repeat('a', 40).'.csv',
    'a trailing newline' => 'contacts-'.str_repeat('a', 40).".csv\n",
]);

it('refuses an existing file whose name is not the one of an export', function (string $file) {
    Storage::disk('local')->put("exports/{$file}", 'secret');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file]))->assertNotFound();
})->with(['too short' => 'contacts-short.xlsx', 'too long' => 'contacts-'.str_repeat('a', 41).'.csv', 'other characters' => 'contacts-'.str_repeat('a', 39).'_.csv']);

it('answers 404 when the file is gone', function () {
    $this->actingAs($this->ann)
        ->get(($this->link)(['file' => 'contacts-'.str_repeat('a', 40).'.xlsx']))
        ->assertNotFound();
});

it('needs the file parameter', function () {
    $this->actingAs($this->ann)->get(($this->link)([]))->assertNotFound();
});

describe('the access of the user at download time', function () {
    beforeEach(function () {
        $this->olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $this->acme = $this->olga->personalOrganization();
        $this->acme->members()->attach($this->ann->id, ['role' => OrganizationRole::Admin->value]);

        $this->acmeLink = fn (string $file): string => ($this->link)(['file' => $file, 'organization' => $this->acme->id]);
        $this->open = fn (string $url) => $this->actingAs($this->ann->fresh())->get($url);
    });

    it('lets an admin or an owner download a contacts, companies, deals, tasks or activities file', function (OrganizationRole $role, string $prefix) {
        $this->acme->members()->updateExistingPivot($this->ann->id, ['role' => $role->value]);
        $file = ($this->stored)('xlsx', "{$prefix} bytes", $prefix);

        $response = ($this->open)(($this->acmeLink)($file));

        $response->assertOk();
        expect($response->streamedContent())->toBe("{$prefix} bytes");
    })->with([OrganizationRole::Admin, OrganizationRole::Owner])->with(['contacts', 'companies', 'deals', 'tasks', 'activities']);

    it('refuses a user who was demoted after asking for the export', function (OrganizationRole $role, string $prefix) {
        $file = ($this->stored)('xlsx', 'secret', $prefix);
        $url = ($this->acmeLink)($file);

        ($this->open)($url)->assertOk();

        $this->acme->members()->updateExistingPivot($this->ann->id, ['role' => $role->value]);

        ($this->open)($url)->assertForbidden();
    })->with([OrganizationRole::Member, OrganizationRole::Viewer])->with(['contacts', 'companies', 'deals', 'tasks', 'activities']);

    it('refuses a user who was removed from the organization', function (string $prefix) {
        $file = ($this->stored)('xlsx', 'secret', $prefix);
        $url = ($this->acmeLink)($file);

        $this->acme->members()->detach($this->ann->id);

        ($this->open)($url)->assertForbidden();
    })->with(['contacts', 'companies', 'deals', 'tasks', 'activities']);

    it('refuses a link signed without an organization', function () {
        $file = ($this->stored)();

        $url = URL::temporarySignedRoute('exports.download', now()->addDays(7), ['file' => $file, 'user' => $this->ann->id]);

        $this->actingAs($this->ann)->get($url)->assertForbidden();
    });

    it('refuses to change the organization of a signed link', function () {
        $file = ($this->stored)();
        $url = ($this->acmeLink)($file);
        $personal = $this->ann->personalOrganization()->id;

        $this->actingAs($this->ann)->get($url)->assertOk();
        $this->actingAs($this->ann)->get(preg_replace('/organization=\d+/', 'organization='.$personal, $url))->assertForbidden();
    });

    it('refuses a link for an organization the user never belonged to', function () {
        $globex = Organization::factory()->create();
        $file = ($this->stored)();

        ($this->open)(($this->link)(['file' => $file, 'organization' => $globex->id]))->assertForbidden();
    });

    it('refuses a link for an organization that does not exist', function (string $organization) {
        $file = ($this->stored)();

        ($this->open)(($this->link)(['file' => $file, 'organization' => $organization]))->assertForbidden();
    })->with(['zero' => '0', 'huge' => '99999999999999999999999', 'unknown' => '987654321']);

    it('refuses a forged organization in a signed link without an error', function (string $suffix) {
        $file = ($this->stored)();

        ($this->open)(($this->link)(['file' => $file, 'organization' => $this->acme->id.$suffix]))->assertForbidden();
    })->with([
        'sql' => ' OR 1=1',
        'decimal' => '.5',
        'exponent' => 'e0',
        'hex' => 'x',
        'plus' => '+',
    ]);

    it('refuses a negative, a signed, a padded, an empty or an array organization', function (string|array $organization) {
        $file = ($this->stored)();

        ($this->open)(($this->link)(['file' => $file, 'organization' => $organization]))->assertForbidden();
    })->with([
        'negative' => '-'.'1',
        'padded' => ' 1',
        'empty' => '',
        'array' => [['1', '2']],
    ]);

    it('checks the file name before the access, and the access before the file', function () {
        $this->acme->members()->detach($this->ann->id);

        ($this->open)(($this->link)(['file' => '../.env', 'organization' => $this->acme->id]))->assertNotFound();
        ($this->open)(($this->link)(['file' => 'contacts-'.str_repeat('a', 40).'.xlsx', 'organization' => $this->acme->id]))->assertForbidden();
    });
});

describe('the policy checked for the prefix of the file', function () {
    it('maps the five exports of today', function () {
        expect(DownloadExportController::EXPORT_MODELS)->toBe(['contacts' => Contact::class, 'companies' => Company::class, 'deals' => Deal::class, 'tasks' => Task::class, 'activities' => Activity::class]);
    });

    it('is told the prefix of every export job', function () {
        $prefixes = collect(glob(app_path('Jobs/*.php')))
            ->map(fn (string $path): string => 'App\\Jobs\\'.basename($path, '.php'))
            ->filter(fn (string $class): bool => in_array(DeliversExport::class, class_uses_recursive($class), true))
            ->map(function (string $class): string {
                $method = new ReflectionMethod($class, 'exportFilePrefix');

                return $method->invoke((new ReflectionClass($class))->newInstanceWithoutConstructor());
            });

        expect($prefixes)->toHaveCount(5)
            ->and(array_keys(DownloadExportController::EXPORT_MODELS))->toContain(...$prefixes->all());
    });

    it('checks the export ability of the mapped model only', function (string $prefix, string $model) {
        $file = ($this->stored)('xlsx', 'bytes', $prefix);
        $checked = [];
        Gate::before(function ($user, string $ability, array $arguments) use (&$checked) {
            if ($ability === 'export') {
                $checked[] = $arguments[0];
            }

            return null;
        });

        $this->actingAs($this->ann)->get(($this->link)(['file' => $file]))->assertOk();

        expect($checked)->toBe([$model]);
    })->with([
        'contacts' => ['contacts', Contact::class],
        'companies' => ['companies', Company::class],
        'deals' => ['deals', Deal::class],
        'tasks' => ['tasks', Task::class],
        'activities' => ['activities', Activity::class],
    ]);

    it('refuses an existing file with a prefix that is not in the map before any access check', function (string $file) {
        Storage::disk('local')->put("exports/{$file}", 'secret');
        $checked = [];
        Gate::before(function ($user, string $ability, array $arguments) use (&$checked) {
            if ($ability === 'export') {
                $checked[] = $arguments[0];
            }

            return null;
        });

        $this->actingAs($this->ann)->get(($this->link)(['file' => $file]))->assertNotFound();

        expect($checked)->toBe([]);
    })->with([
        'invoices' => 'invoices-'.str_repeat('a', 40).'.xlsx',
        'segments' => 'segments-'.str_repeat('a', 40).'.csv',
        'an upper-case prefix' => 'Contacts-'.str_repeat('a', 40).'.xlsx',
        'a failed rows file' => 'failed-'.str_repeat('a', 40).'.csv',
    ]);
});
