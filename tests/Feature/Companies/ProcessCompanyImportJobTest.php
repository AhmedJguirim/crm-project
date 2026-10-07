<?php

use App\Exceptions\UnreadableImportFileException;
use App\Jobs\ProcessCompanyImportJob;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\SyncSegmentMembership;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Segment;
use App\Models\User;
use App\Services\ContactImportFileReader;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();

    $this->sme = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'SME']);
    $this->vat = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'VAT Number', 'type' => 'text', 'unique' => true, 'order' => 1]);
    Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Initech', 'website' => 'initech.com']);

    $this->importCompanies = function (string $content, bool $windows1252 = false, string $extension = 'csv'): DatabaseNotification {
        $path = 'company-imports/test-'.uniqid().'.'.$extension;
        Storage::disk('local')->put($path, $windows1252 ? iconv('UTF-8', 'Windows-1252', $content) : $content);

        DatabaseNotification::query()->delete();
        ProcessCompanyImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    };

    $this->companyNamed = fn (string $name): ?Company => Company::forOrganization($this->org->id)->where('name', $name)->first();
    $this->failedRowsFile = function (): string {
        $file = collect(Storage::disk('local')->files('contact-imports'))->first(fn (string $file): bool => str_starts_with(basename($file), 'failed-'));

        return Storage::disk('local')->get($file);
    };
});

it('imports three rows, one of them an existing company', function () {
    $notification = ($this->importCompanies)("name,website,type,industry,employees\nNova,nova.io,sme,software,85\nInitech,initech.com,,,\nGlobex,,SME,Finance & Banking,\n");

    expect($notification->data['title'])->toBe('Import complete with errors')
        ->and($notification->data['body'])->toContain('Imported: 2 | Failed: 1')
        ->and($notification->data['body'])->toContain('Row 2: A company named Initech (initech.com) already exists.')
        ->and(($this->companyNamed)('Nova')->employees)->toBe(85)
        ->and(($this->companyNamed)('Globex')->company_type_id)->toBe($this->sme->id)
        ->and(Storage::disk('local')->files('company-imports'))->toBe([]);
});

it('says so when every row was imported', function () {
    $notification = ($this->importCompanies)("name,website\nNova,nova.io\nZeta,\n");

    expect($notification->data['title'])->toBe('Import complete')
        ->and($notification->data['body'])->toBe('Imported: 2 | Failed: 0')
        ->and($notification->data['actions'])->toBe([]);
});

it('matches loose headers and lists the ignored columns', function () {
    $notification = ($this->importCompanies)(" Name ,WEBSITE,Shoe size\nNova,nova.io,43\n");

    expect($notification->data['title'])->toBe('Import complete, some columns were ignored')
        ->and($notification->data['body'])->toContain('Imported: 1 | Failed: 0')
        ->and($notification->data['body'])->toContain('Ignored columns (no matching field): "Shoe size"')
        ->and(($this->companyNamed)('Nova')->domain)->toBe('nova.io');
});

it('knows the id column of an exported file', function () {
    $notification = ($this->importCompanies)("id,name\n7,Nova\n");

    expect(($this->companyNamed)('Nova'))->not->toBeNull()
        ->and($notification->data['title'])->toBe('Import complete')
        ->and($notification->data['body'])->not->toContain('Ignored columns');
});

it('fails the file when the id column is named twice', function () {
    $notification = ($this->importCompanies)("id,ID ,name\n7,7,Nova\n");

    expect($notification->data['title'])->toBe('Import failed')
        ->and($notification->data['body'])->toBe('Two columns are named "id". Keep only one and import the file again.')
        ->and(($this->companyNamed)('Nova'))->toBeNull();
});

it('fails the file when two headers name one column', function () {
    $notification = ($this->importCompanies)("name,Name,website\nNova,Nova,nova.io\n");

    expect($notification->data['title'])->toBe('Import failed')
        ->and($notification->data['body'])->toBe('Two columns are named "name". Keep only one and import the file again.')
        ->and(($this->companyNamed)('Nova'))->toBeNull();
});

it('imports a file saved by a French Excel and writes the failed rows with ; in UTF-8 with a BOM', function () {
    $notification = ($this->importCompanies)("name;website;annual revenue;VAT Number;city\nSociété Générale;sg.fr;1250,5;FR1;Paris\nBroken;;abc;;\n", windows1252: true);

    $company = ($this->companyNamed)('Société Générale');
    $failed = ($this->failedRowsFile)();

    expect($notification->data['body'])->toContain('Imported: 1 | Failed: 1')
        ->and((string) $company->annual_revenue)->toBe('1250.50')
        ->and(Address::forOrganization($this->org->id)->sole()->city)->toBe('Paris')
        ->and($failed)->toStartWith("\xEF\xBB\xBF")
        ->and($failed)->toContain("_row_number;_error;name;website;\"annual revenue\";\"VAT Number\";city\n")
        ->and($failed)->toContain("Invalid value for field 'annual revenue': abc")
        ->and(mb_check_encoding($failed, 'UTF-8'))->toBeTrue();
});

it('can import its own failed rows file again once corrected', function () {
    ($this->importCompanies)("name,website\nNova,nova.io\nInitech,initech.com\n");

    $failed = ($this->failedRowsFile)();
    $corrected = str_replace('Initech,initech.com', 'Initech Two,initech-two.com', $failed);

    $notification = ($this->importCompanies)($corrected);

    expect($notification->data['body'])->toContain('Imported: 1 | Failed: 0')
        ->and(($this->companyNamed)('Initech Two'))->not->toBeNull();
});

it('offers the failed rows to the user who imported only', function () {
    $notification = ($this->importCompanies)("name\nInitech\n");
    $url = $notification->data['actions'][0]['url'];
    $stranger = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->actingAs($this->user)->get($url)->assertOk();
    $this->actingAs($stranger)->get($url)->assertForbidden();
});

it('does not queue a segment sync', function () {
    Queue::fake([SyncSegmentMembership::class]);
    Segment::factory()->create(['organization_id' => $this->org->id, 'is_published' => true]);

    ($this->importCompanies)("name\nNova\n");

    Queue::assertNotPushed(SyncSegmentMembership::class);
});

it('waits for contact imports of the same organization, and not for another organization', function () {
    $company = (new ProcessCompanyImportJob('company-imports/a.csv', $this->org->id, $this->user->id))->middleware();
    $contact = (new ProcessContactImportJob('contact-imports/a.csv', $this->org->id, $this->user->id))->middleware();
    $other = (new ProcessCompanyImportJob('company-imports/a.csv', $this->org->id + 1, $this->user->id))->middleware();

    expect($company[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($company[0]->key)->toBe($contact[0]->key)
        ->and($company[0]->key)->not->toBe($other[0]->key)
        ->and($company[0]->releaseAfter)->toBe($contact[0]->releaseAfter)
        ->and($company[0]->expiresAfter)->toBe($contact[0]->expiresAfter)
        ->and($company)->toHaveCount(2);
});

it('runs on the imports queue of the long-running connection', function () {
    $job = new ProcessCompanyImportJob('company-imports/a.csv', $this->org->id, $this->user->id);

    expect($job->queue)->toBe('imports')
        ->and($job->connection)->toBe(config('queue.long_running_connection'))
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->maxExceptions)->toBe(1);
});

it('tells the user when the job fails', function () {
    Storage::disk('local')->put('company-imports/a.csv', 'name');
    DatabaseNotification::query()->delete();

    (new ProcessCompanyImportJob('company-imports/a.csv', $this->org->id, $this->user->id))->failed(new RuntimeException('boom'));

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();

    expect($notification->data['title'])->toBe('Import failed')
        ->and($notification->data['body'])->toContain('Companies already imported were kept')
        ->and(Storage::disk('local')->exists('company-imports/a.csv'))->toBeFalse();
});

it('keeps what was imported when the file becomes unreadable partway', function () {
    $path = 'company-imports/partial-'.uniqid().'.csv';
    Storage::disk('local')->put($path, "name\nNova\n");

    $reader = Mockery::mock(ContactImportFileReader::class)->makePartial();
    $reader->shouldReceive('rows')->andReturnUsing(function () {
        yield ['name'];
        yield ['Nova'];

        throw UnreadableImportFileException::unreadable(new RuntimeException('cut'));
    });
    $this->app->instance(ContactImportFileReader::class, $reader);
    DatabaseNotification::query()->delete();

    ProcessCompanyImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();

    expect($notification->data['title'])->toBe('Import stopped partway')
        ->and($notification->data['body'])->toContain('Imported: 1 | Failed: 0. The file could not be read after row 1')
        ->and(($this->companyNamed)('Nova'))->not->toBeNull();
});
