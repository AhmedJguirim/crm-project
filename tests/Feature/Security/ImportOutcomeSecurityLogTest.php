<?php

use App\Enums\ImportMode;
use App\Enums\OrganizationRole;
use App\Exceptions\UnreadableImportFileException;
use App\Jobs\ProcessCompanyImportJob;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\SyncSegmentMembership;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\User;
use App\Services\ContactImportFileReader;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;

/**
 * SEC-59: every contact and company import run writes one `data.import_finished` line (list, mode, outcome and counts,
 * ids only). The jobs run as in a worker: no Filament tenant is set.
 */
function importOutcomeLogSetup(): object
{
    Storage::fake('local');
    Queue::fake([SyncSegmentMembership::class]);

    $ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $ann->id]);
    $acme->members()->attach($ann->id, ['role' => OrganizationRole::Admin->value]);

    return (object) ['ann' => $ann, 'acme' => $acme, 'log' => fakeSecurityLog()];
}

/**
 * @return list<array{event: string, level: string, context: array<string, mixed>}>
 */
function importOutcomeLines(TestHandler $log): array
{
    return array_values(array_filter(securityLogEntries($log), fn (array $entry): bool => $entry['event'] === 'data.import_finished'));
}

/**
 * @return array<string, mixed>
 */
function importOutcomeContext(object $test, array $fields, ?int $userId = null): array
{
    return [...$fields, 'import_organization_id' => $test->acme->id, 'user_id' => $userId ?? $test->ann->id, 'organization_id' => null, 'ip' => '127.0.0.1'];
}

/**
 * Two rows that import and one that fails, in the shape each list reads.
 */
function importOutcomeFile(string $job): string
{
    return $job === ProcessContactImportJob::class
        ? "name,email\nAnn Doe,ann.doe@x.test\nZed Roe,zed.roe@x.test\nBad,not-an-email\n"
        : "name,website\nNova,nova.test\nZeta,zeta.test\n,missing.test\n";
}

function importOutcomeStore(string $directory, string $content): string
{
    $path = $directory.'/test-'.uniqid().'.csv';
    Storage::disk('local')->put($path, $content);

    return $path;
}

/**
 * @param  list<list<string>>  $rows  the rows the reader yields (header first) before the file turns unreadable
 */
function importOutcomeReaderCutAfter(array $rows): void
{
    $reader = Mockery::mock(ContactImportFileReader::class)->makePartial();
    $reader->shouldReceive('rows')->andReturnUsing(function () use ($rows) {
        yield from $rows;

        throw UnreadableImportFileException::unreadable(new RuntimeException('cut'));
    });
    app()->instance(ContactImportFileReader::class, $reader);
}

function importOutcomeReaderCutAfterOneRow(): void
{
    importOutcomeReaderCutAfter([['name'], ['Nova']]);
}

it('writes one completed line for a contact import that creates', function () {
    $test = importOutcomeLogSetup();
    $path = importOutcomeStore('contact-imports', "name,email\nAnn Doe,ann.doe@x.test\nZed Roe,zed.roe@x.test\nBad,not-an-email\n");

    ProcessContactImportJob::dispatchSync($path, $test->acme->id, $test->ann->id, ImportMode::CreateOnly);

    $lines = importOutcomeLines($test->log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('info')
        ->and($lines[0]['context'])->toBe(importOutcomeContext($test, ['list' => 'contacts', 'mode' => 'create', 'outcome' => 'completed', 'created' => 2, 'updated' => 0, 'failed' => 1]));
});

it('counts created and updated contacts apart in create and update mode', function () {
    $test = importOutcomeLogSetup();
    Contact::factory()->create(['organization_id' => $test->acme->id, 'name' => 'Ann Doe', 'email' => 'ann.doe@x.test']);
    $path = importOutcomeStore('contact-imports', "name,email\nAnn Doe,ann.doe@x.test\nNew Person,new@x.test\nBad,not-an-email\n");

    ProcessContactImportJob::dispatchSync($path, $test->acme->id, $test->ann->id, ImportMode::CreateAndUpdate);

    $lines = importOutcomeLines($test->log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context'])->toBe(importOutcomeContext($test, ['list' => 'contacts', 'mode' => 'create_and_update', 'outcome' => 'completed', 'created' => 1, 'updated' => 1, 'failed' => 1]));

    $body = DatabaseNotification::where('notifiable_id', $test->ann->id)->sole()->data['body'];

    expect($body)->toContain('Created: 1 | Updated: 1 | Failed: 1');
});

it('writes the counts of a company import in update only mode', function () {
    $test = importOutcomeLogSetup();
    Company::factory()->create(['organization_id' => $test->acme->id, 'name' => 'Initech', 'website' => 'initech.com']);
    $path = importOutcomeStore('company-imports', "name,website\nInitech,initech.com\nNova,nova.test\n");

    ProcessCompanyImportJob::dispatchSync($path, $test->acme->id, $test->ann->id, ImportMode::UpdateOnly);

    $lines = importOutcomeLines($test->log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context'])->toBe(importOutcomeContext($test, ['list' => 'companies', 'mode' => 'update', 'outcome' => 'completed', 'created' => 0, 'updated' => 1, 'failed' => 1]));

    $body = DatabaseNotification::where('notifiable_id', $test->ann->id)->sole()->data['body'];

    expect($body)->toContain('Created: 0 | Updated: 1 | Failed: 1');
});

it('writes outcome unreadable with zero counts for a file that cannot be read', function (string $job, string $directory, string $list) {
    $test = importOutcomeLogSetup();
    $path = importOutcomeStore($directory, "name,name\nAnn Doe,Ann Roe\n");

    $job::dispatchSync($path, $test->acme->id, $test->ann->id, ImportMode::CreateOnly);

    $lines = importOutcomeLines($test->log);

    expect(DatabaseNotification::where('notifiable_id', $test->ann->id)->sole()->data['title'])->toBe('Import failed')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['context'])->toBe(importOutcomeContext($test, ['list' => $list, 'mode' => 'create', 'outcome' => 'unreadable', 'created' => 0, 'updated' => 0, 'failed' => 0]));
})->with([
    'contacts' => [ProcessContactImportJob::class, 'contact-imports', 'contacts'],
    'companies' => [ProcessCompanyImportJob::class, 'company-imports', 'companies'],
]);

it('writes outcome stopped for a file cut after one row', function (string $job, string $directory, string $list) {
    $test = importOutcomeLogSetup();
    $path = importOutcomeStore($directory, "name\nNova\n");
    importOutcomeReaderCutAfterOneRow();

    $job::dispatchSync($path, $test->acme->id, $test->ann->id, ImportMode::CreateOnly);

    $lines = importOutcomeLines($test->log);

    expect(DatabaseNotification::where('notifiable_id', $test->ann->id)->sole()->data['title'])->toBe('Import stopped partway')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['context'])->toBe(importOutcomeContext($test, ['list' => $list, 'mode' => 'create', 'outcome' => 'stopped', 'created' => 1, 'updated' => 0, 'failed' => 0]));
})->with([
    'companies' => [ProcessCompanyImportJob::class, 'company-imports', 'companies'],
    'contacts' => [ProcessContactImportJob::class, 'contact-imports', 'contacts'],
]);

it('writes outcome stopped for a file cut after only failed rows', function (string $job, string $directory, string $list, array $rows) {
    $test = importOutcomeLogSetup();
    $path = importOutcomeStore($directory, "name\n");
    importOutcomeReaderCutAfter($rows);

    $job::dispatchSync($path, $test->acme->id, $test->ann->id, ImportMode::CreateOnly);

    $lines = importOutcomeLines($test->log);

    expect(DatabaseNotification::where('notifiable_id', $test->ann->id)->sole()->data['title'])->toBe('Import stopped partway')
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['context'])->toBe(importOutcomeContext($test, ['list' => $list, 'mode' => 'create', 'outcome' => 'stopped', 'created' => 0, 'updated' => 0, 'failed' => 1]));
})->with([
    'contacts' => [ProcessContactImportJob::class, 'contact-imports', 'contacts', [['name', 'email'], ['Bad', 'not-an-email']]],
    'companies' => [ProcessCompanyImportJob::class, 'company-imports', 'companies', [['name', 'website'], ['', 'missing.test']]],
]);

it('writes outcome aborted without counts when the job did not finish', function (string $job, string $directory, string $list) {
    $test = importOutcomeLogSetup();

    (new $job($directory.'/a.csv', $test->acme->id, $test->ann->id, ImportMode::CreateAndUpdate))->failed(new RuntimeException('boom'));

    $lines = importOutcomeLines($test->log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['level'])->toBe('info')
        ->and($lines[0]['context'])->toBe(importOutcomeContext($test, ['list' => $list, 'mode' => 'create_and_update', 'outcome' => 'aborted']));
})->with([
    'contacts' => [ProcessContactImportJob::class, 'contact-imports', 'contacts'],
    'companies' => [ProcessCompanyImportJob::class, 'company-imports', 'companies'],
]);

it('still logs an import whose user was deleted, with a null user id', function (string $job, string $directory, string $list) {
    $test = importOutcomeLogSetup();
    $path = importOutcomeStore($directory, importOutcomeFile($job));
    $annId = $test->ann->id;
    $test->ann->delete();

    $job::dispatchSync($path, $test->acme->id, $annId, ImportMode::CreateOnly);

    $lines = importOutcomeLines($test->log);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context']['outcome'])->toBe('completed')
        ->and($lines[0]['context']['list'])->toBe($list)
        ->and($lines[0]['context']['user_id'])->toBeNull()
        ->and($lines[0]['context']['failed'])->toBe(1)
        ->and(DatabaseNotification::query()->count())->toBe(0);
})->with([
    'contacts' => [ProcessContactImportJob::class, 'contact-imports', 'contacts'],
    'companies' => [ProcessCompanyImportJob::class, 'company-imports', 'companies'],
]);

it('still logs an unreadable, stopped or aborted import whose user was deleted', function () {
    $test = importOutcomeLogSetup();
    $annId = $test->ann->id;
    $test->ann->delete();

    ProcessContactImportJob::dispatchSync(importOutcomeStore('contact-imports', "name,email,email\nA,a@x.test,b@x.test\n"), $test->acme->id, $annId, ImportMode::CreateOnly);

    $path = importOutcomeStore('company-imports', "name\nNova\n");
    importOutcomeReaderCutAfterOneRow();
    ProcessCompanyImportJob::dispatchSync($path, $test->acme->id, $annId, ImportMode::CreateOnly);

    (new ProcessCompanyImportJob('company-imports/a.csv', $test->acme->id, $annId))->failed(new RuntimeException('boom'));

    expect(array_column(array_column(importOutcomeLines($test->log), 'context'), 'outcome'))->toBe(['unreadable', 'stopped', 'aborted'])
        ->and(array_unique(array_column(array_column(importOutcomeLines($test->log), 'context'), 'user_id')))->toBe([null]);
});

it('never puts file names, headers, row data or error texts in the line', function (string $job, string $directory) {
    $test = importOutcomeLogSetup();
    Contact::factory()->create(['organization_id' => $test->acme->id, 'name' => 'Zed Roe', 'email' => 'zed.roe@x.test']);
    Company::factory()->create(['organization_id' => $test->acme->id, 'name' => 'Zed Roe', 'website' => 'zed.test']);
    $path = $directory.'/leak-check.csv';
    $content = $job === ProcessContactImportJob::class
        ? "name,email,Secret Column\nZed Roe,zed.roe@x.test,s1\nBad,not-an-email,s2\n"
        : "name,website,Secret Column\nZed Roe,zed.test,s1\n,missing.test,s2\n";
    Storage::disk('local')->put($path, $content);

    $job::dispatchSync($path, $test->acme->id, $test->ann->id, ImportMode::CreateOnly);

    $lines = importOutcomeLines($test->log);
    $json = json_encode(array_column(securityLogEntries($test->log), 'context'), JSON_THROW_ON_ERROR);

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['context']['failed'])->toBe(2);

    foreach (['@', 'x.test', 'zed.test', 'Zed', 'Secret', 'leak-check', 'imports/', 'not-an-email', 'already exists', 'Invalid email', 'failed-'] as $forbidden) {
        expect($json)->not->toContain($forbidden);
    }
})->with([
    'contacts' => [ProcessContactImportJob::class, 'contact-imports'],
    'companies' => [ProcessCompanyImportJob::class, 'company-imports'],
]);

it('does not change an import when the security log cannot be written', function () {
    $test = importOutcomeLogSetup();
    config(['logging.channels.security' => ['driver' => 'single', 'path' => '/proc/forbidden/security.log']]);
    Log::forgetChannel('security');
    $path = importOutcomeStore('contact-imports', "name,email\nAnn Doe,ann.doe@x.test\n");

    ProcessContactImportJob::dispatchSync($path, $test->acme->id, $test->ann->id, ImportMode::CreateOnly);

    expect(Contact::forOrganization($test->acme->id)->count())->toBe(1)
        ->and(DatabaseNotification::where('notifiable_id', $test->ann->id)->sole()->data['title'])->toBe('Import complete');
});
