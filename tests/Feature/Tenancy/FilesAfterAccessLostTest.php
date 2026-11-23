<?php

use App\Enums\OrganizationRole;
use App\Exceptions\UnreadableImportFileException;
use App\Jobs\ExportContactsJob;
use App\Jobs\ProcessCompanyImportJob;
use App\Jobs\ProcessContactImportJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Support\CsvDialect;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/*
 * BUG-81: an export or import that ends after its user lost access keeps no file and sends no notification.
 */

beforeEach(function () {
    Storage::fake('local');

    $this->olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $this->olga->id]);
    $this->acme->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);
    $this->acme->members()->attach($this->ann->id, ['role' => OrganizationRole::Admin->value]);

    $this->zed = Contact::factory()->for($this->acme)->create(['name' => 'Zed', 'email' => 'zed@example.com']);

    $this->annNotifications = fn (): int => DatabaseNotification::where('notifiable_id', $this->ann->id)->count();

    $this->upload = function (string $path, string $content): string {
        Storage::disk('local')->put($path, $content);

        return $path;
    };

    $this->failedFiles = fn (): array => array_values(array_filter(
        Storage::disk('local')->allFiles(),
        fn (string $file): bool => str_contains($file, "failed-o{$this->acme->id}-u{$this->ann->id}-"),
    ));

    $this->loseAccess = function (string $change): void {
        match ($change) {
            'detached' => $this->acme->members()->detach($this->ann->id),
            'member' => $this->acme->members()->updateExistingPivot($this->ann->id, ['role' => OrganizationRole::Member->value]),
            'viewer' => $this->acme->members()->updateExistingPivot($this->ann->id, ['role' => OrganizationRole::Viewer->value]),
        };
    };
});

function bindLateReaderUnreadableAfter(array $rows): void
{
    app()->bind(ContactImportFileReader::class, fn () => new class($rows) extends ContactImportFileReader
    {
        /** @param  array<int, array<int, string>>  $rows */
        public function __construct(private readonly array $rows) {}

        public function rows(string $absolutePath, string $extension, ?CsvDialect $dialect = null): Generator
        {
            yield from $this->rows;

            throw UnreadableImportFileException::unreadable(new RuntimeException('Corrupt file'));
        }
    });
}

test('an export that ends after the user lost access keeps no file and sends no notification', function (string $change, string $format) {
    ($this->loseAccess)($change);

    ExportContactsJob::dispatchSync([$this->zed->id], $this->acme->id, $this->ann->id, $format, "contacts-2026-11-23.{$format}");

    expect(Storage::disk('local')->files('exports'))->toBe([])
        ->and(($this->annNotifications)())->toBe(0);
})->with([
    'detached xlsx' => ['detached', 'xlsx'],
    'detached csv' => ['detached', 'csv'],
    'member xlsx' => ['member', 'xlsx'],
    'viewer csv' => ['viewer', 'csv'],
]);

test('an export by a member who still may export is unchanged', function () {
    ExportContactsJob::dispatchSync([$this->zed->id], $this->acme->id, $this->ann->id, 'xlsx', 'contacts-2026-11-23.xlsx');

    $files = Storage::disk('local')->files('exports');
    $notifications = DatabaseNotification::where('notifiable_id', $this->ann->id)->get();

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toMatch("/^contacts-o{$this->acme->id}-u{$this->ann->id}-/")
        ->and($notifications)->toHaveCount(1)
        ->and($notifications[0]->data['title'])->toBe('Export ready');
});

test('the export of the other organization of the user is not touched', function () {
    $this->acme->members()->detach($this->ann->id);
    $personal = $this->ann->personalOrganization();

    ExportContactsJob::dispatchSync([], $personal->id, $this->ann->id, 'xlsx', 'contacts-2026-11-23.xlsx');

    $files = Storage::disk('local')->files('exports');

    expect($files)->toHaveCount(1)
        ->and(basename($files[0]))->toContain("-o{$personal->id}-u{$this->ann->id}-")
        ->and(($this->annNotifications)())->toBe(1);
});

test('an export for an organization the user was never in keeps no file', function () {
    $other = Organization::factory()->create(['created_by' => $this->olga->id]);
    $other->members()->attach($this->olga->id, ['role' => OrganizationRole::Owner->value]);

    ExportContactsJob::dispatchSync([], $other->id, $this->ann->id, 'csv', 'contacts-2026-11-23.csv');

    expect(Storage::disk('local')->files('exports'))->toBe([])
        ->and(($this->annNotifications)())->toBe(0);
});

test('a deleted organization keeps no export file', function () {
    $this->acme->delete();

    ExportContactsJob::dispatchSync([], $this->acme->id, $this->ann->id, 'csv', 'contacts-2026-11-23.csv');

    expect(Storage::disk('local')->files('exports'))->toBe([])
        ->and(($this->annNotifications)())->toBe(0);
});

test('an import with failed rows that ends after the user lost access keeps no failed rows file', function (string $job, string $upload, string $csv, string $change) {
    $path = ($this->upload)($upload, $csv);
    ($this->loseAccess)($change);

    $job::dispatchSync($path, $this->acme->id, $this->ann->id);

    expect(($this->failedFiles)())->toBe([])
        ->and(($this->annNotifications)())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
})->with([
    'contacts detached' => [ProcessContactImportJob::class, 'contact-imports/acme.csv', "name,email,phone,tags\nBroken,not-an-email,,\n", 'detached'],
    'contacts member' => [ProcessContactImportJob::class, 'contact-imports/acme.csv', "name,email,phone,tags\nBroken,not-an-email,,\n", 'member'],
    'companies detached' => [ProcessCompanyImportJob::class, 'company-imports/acme.csv', "name,website\n,initech.com\n", 'detached'],
    'companies member' => [ProcessCompanyImportJob::class, 'company-imports/acme.csv', "name,website\n,initech.com\n", 'member'],
]);

test('an import without failed rows sends no notification to a removed member but still imports', function (string $job, string $upload, string $csv) {
    $path = ($this->upload)($upload, $csv);
    ($this->loseAccess)('detached');

    $job::dispatchSync($path, $this->acme->id, $this->ann->id);

    expect(($this->annNotifications)())->toBe(0)
        ->and(Contact::forOrganization($this->acme->id)->where('email', 'john@example.com')->exists())->toBe($job === ProcessContactImportJob::class)
        ->and(Company::forOrganization($this->acme->id)->where('name', 'Initech')->exists())->toBe($job === ProcessCompanyImportJob::class);
})->with([
    'contacts' => [ProcessContactImportJob::class, 'contact-imports/ok.csv', "name,email\nJohn Doe,john@example.com\n"],
    'companies' => [ProcessCompanyImportJob::class, 'company-imports/ok.csv', "name\nInitech\n"],
]);

test('an unreadable file with no rows sends no "Import failed" to a removed member', function (string $job, string $upload) {
    $path = ($this->upload)($upload, 'this is not a spreadsheet');
    ($this->loseAccess)('detached');

    $job::dispatchSync($path, $this->acme->id, $this->ann->id);

    expect(($this->annNotifications)())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse();
})->with([
    'contacts' => [ProcessContactImportJob::class, 'contact-imports/bad.xlsx'],
    'companies' => [ProcessCompanyImportJob::class, 'company-imports/bad.xlsx'],
]);

test('an unreadable file with no rows still tells a member who may import', function () {
    $path = ($this->upload)('contact-imports/bad.xlsx', 'this is not a spreadsheet');

    ProcessContactImportJob::dispatchSync($path, $this->acme->id, $this->ann->id);

    expect(DatabaseNotification::where('notifiable_id', $this->ann->id)->first()->data['title'])->toBe('Import failed');
});

test('a file that becomes unreadable after failed rows keeps no failed rows file for a removed member', function () {
    $path = ($this->upload)('contact-imports/late.csv', "name,email,phone,tags\n");
    bindLateReaderUnreadableAfter([['name', 'email', 'phone', 'tags'], ['Broken', 'not-an-email', '', '']]);
    ($this->loseAccess)('detached');

    ProcessContactImportJob::dispatchSync($path, $this->acme->id, $this->ann->id);

    expect(($this->failedFiles)())->toBe([])
        ->and(($this->annNotifications)())->toBe(0);
});

test('a file that becomes unreadable after failed rows is unchanged for a member who still may import', function () {
    $path = ($this->upload)('contact-imports/late.csv', "name,email,phone,tags\n");
    bindLateReaderUnreadableAfter([['name', 'email', 'phone', 'tags'], ['Broken', 'not-an-email', '', '']]);

    ProcessContactImportJob::dispatchSync($path, $this->acme->id, $this->ann->id);

    $notifications = DatabaseNotification::where('notifiable_id', $this->ann->id)->get();

    expect(($this->failedFiles)())->toHaveCount(1)
        ->and($notifications)->toHaveCount(1)
        ->and($notifications[0]->data['title'])->toBe('Import stopped partway');
});

test('an import by a member who still may import is unchanged', function (string $job, string $upload, string $csv) {
    $path = ($this->upload)($upload, $csv);

    $job::dispatchSync($path, $this->acme->id, $this->ann->id);

    $notifications = DatabaseNotification::where('notifiable_id', $this->ann->id)->get();

    expect(($this->failedFiles)())->toHaveCount(1)
        ->and($notifications)->toHaveCount(1)
        ->and($notifications[0]->data['title'])->toBe('Import complete with errors');
})->with([
    'contacts' => [ProcessContactImportJob::class, 'contact-imports/acme.csv', "name,email,phone,tags\nBroken,not-an-email,,\n"],
    'companies' => [ProcessCompanyImportJob::class, 'company-imports/acme.csv', "name,website\n,initech.com\n"],
]);

test('access lost while the failed rows file is written deletes that file and sends nothing', function (string $job, string $csv, ?array $lateRows) {
    $path = ($this->upload)('contact-imports/acme.csv', $csv);
    $checks = 0;

    if ($lateRows !== null) {
        bindLateReaderUnreadableAfter($lateRows);
    }

    Gate::before(function (User $user, string $ability) use (&$checks): void {
        if ($ability === 'import' && ++$checks === 2) {
            $this->acme->members()->detach($this->ann->id);
        }
    });

    $job::dispatchSync($path, $this->acme->id, $this->ann->id);

    expect($checks)->toBe(2)
        ->and(($this->failedFiles)())->toBe([])
        ->and(($this->annNotifications)())->toBe(0);
})->with([
    'contacts' => [ProcessContactImportJob::class, "name,email,phone,tags\nBroken,not-an-email,,\n", null],
    'companies' => [ProcessCompanyImportJob::class, "name,website\n,initech.com\n", null],
    'contacts unreadable' => [ProcessContactImportJob::class, '', [['name', 'email', 'phone', 'tags'], ['Broken', 'not-an-email', '', '']]],
    'companies unreadable' => [ProcessCompanyImportJob::class, '', [['name', 'website'], ['', 'initech.com']]],
]);
