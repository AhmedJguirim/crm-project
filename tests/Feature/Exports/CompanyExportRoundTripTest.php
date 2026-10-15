<?php

use App\Enums\ImportMode;
use App\Jobs\ExportCompaniesJob;
use App\Jobs\ProcessCompanyImportJob;
use App\Models\Company;
use App\Models\User;
use App\Services\Imports\TextPreservingExcelReader;
use Database\Seeders\ItConsultingSeeder;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * What the import has to give back for a company: its fields, the name of its type, its address and its custom field values.
 *
 * @return array<string, mixed>
 */
function companyRoundTripSnapshot(Company $company): array
{
    $company->load(['address', 'companyType']);

    return [
        'name' => $company->name,
        'website' => $company->website,
        'type' => $company->companyType?->name,
        'phone' => $company->phone,
        'industry' => $company->industry,
        'employees' => $company->employees,
        'annual_revenue' => $company->annual_revenue,
        'street' => $company->address?->street,
        'city' => $company->address?->city,
        'zip' => $company->address?->zip,
        'country' => $company->address?->country,
        'notes' => $company->notes,
        'owner_id' => $company->owner_id,
        'custom_field_values' => collect($company->custom_field_values)->sortKeys()->all(),
    ];
}

beforeEach(function () {
    Storage::fake('local');

    $this->seed(ItConsultingSeeder::class);

    $this->user = User::query()->where('email', 'test@example.com')->firstOrFail();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->exportAll = function (string $format = 'xlsx'): string {
        Storage::disk('local')->deleteDirectory('exports');
        $ids = Company::forOrganization($this->org->id)->orderBy('id')->pluck('id')->all();

        ExportCompaniesJob::dispatchSync($ids, $this->org->id, $this->user->id, $format, "companies-2026-10-07.{$format}");

        return Storage::disk('local')->get(Storage::disk('local')->files('exports')[0]);
    };

    $this->removeFromDatabase = function (Company $company): void {
        DB::transaction(function () use ($company): void {
            DB::table('company_contact')->where('company_id', $company->id)->delete();
            DB::table('companies')->where('id', $company->id)->delete();
        });
    };

    $this->importFile = function (string $contents, string $extension): DatabaseNotification {
        Storage::disk('local')->put("company-imports/round-trip.{$extension}", $contents);
        DatabaseNotification::query()->delete();

        ProcessCompanyImportJob::dispatchSync("company-imports/round-trip.{$extension}", $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    };

    $this->richCompany = fn (): Company => Company::forOrganization($this->org->id)->where('name', 'TechCorp Solutions')->firstOrFail();
});

it('recreates a deleted company from the exported file, and refuses the others as existing', function (string $format) {
    $company = ($this->richCompany)();
    $before = companyRoundTripSnapshot($company);
    $total = Company::forOrganization($this->org->id)->count();
    $file = ($this->exportAll)($format);

    expect($before['custom_field_values'])->not->toBeEmpty()
        ->and($before['street'])->not->toBeNull()
        ->and($before['type'])->not->toBeNull()
        ->and($before['annual_revenue'])->not->toBeNull()
        ->and($before['owner_id'])->toBe($this->user->id);

    ($this->removeFromDatabase)($company);
    expect(Company::forOrganization($this->org->id)->count())->toBe($total - 1);

    $notification = ($this->importFile)($file, $format);
    $after = companyRoundTripSnapshot(Company::forOrganization($this->org->id)->where('name', $before['name'])->sole());

    expect($notification->data['body'])->toContain('Imported: 1 | Failed: '.($total - 1))
        ->and($notification->data['body'])->toContain('already exists.')
        ->and($after)->toEqual($before);
})->with(['xlsx', 'csv']);

it('gives back an unowned company as unowned, and fails the same rows again from the failed rows file', function (string $format) {
    $owned = ($this->richCompany)();
    $unowned = Company::forOrganization($this->org->id)->where('name', 'InnoTech SA')->firstOrFail();
    $before = [companyRoundTripSnapshot($owned), companyRoundTripSnapshot($unowned)];
    $total = Company::forOrganization($this->org->id)->count();
    $file = ($this->exportAll)($format);

    ($this->removeFromDatabase)($owned);
    ($this->removeFromDatabase)($unowned);

    $notification = ($this->importFile)($file, $format);
    $after = [
        companyRoundTripSnapshot(Company::forOrganization($this->org->id)->where('name', $owned->name)->sole()),
        companyRoundTripSnapshot(Company::forOrganization($this->org->id)->where('name', $unowned->name)->sole()),
    ];

    expect($before[1]['owner_id'])->toBeNull()
        ->and($notification->data['body'])->toContain('Imported: 2 | Failed: '.($total - 2))
        ->and($after)->toEqual($before);

    $failedFiles = fn (): array => collect(Storage::disk('local')->files('contact-imports'))->filter(fn (string $path): bool => str_starts_with(basename($path), 'failed-'))->values()->all();
    $errors = fn (string $path): array => SimpleExcelReader::create(Storage::disk('local')->path($path), 'csv')->getRows()->map(fn (array $row): array => [$row['name'], $row['_error']])->sortBy(0)->values()->all();
    [$firstFailed] = $failedFiles();
    $firstErrors = $errors($firstFailed);
    $failedContents = Storage::disk('local')->get($firstFailed);
    Storage::disk('local')->delete($firstFailed);

    $second = ($this->importFile)($failedContents, 'csv');
    [$secondFailed] = $failedFiles();

    expect($second->data['body'])->toContain('Imported: 0 | Failed: '.($total - 2))
        ->and($errors($secondFailed))->toBe($firstErrors)
        ->and($firstErrors)->toHaveCount($total - 2);
})->with(['xlsx', 'csv']);

it('gives back a company whose name starts with an equals sign', function (string $format) {
    $company = ($this->richCompany)();
    $company->update(['name' => '=1+1', 'notes' => '@home']);
    $total = Company::forOrganization($this->org->id)->count();
    $file = ($this->exportAll)($format);

    ($this->removeFromDatabase)($company);

    $notification = ($this->importFile)($file, $format);
    $after = Company::forOrganization($this->org->id)->where('website', $company->website)->sole();

    expect($notification->data['body'])->toContain('Imported: 1 | Failed: '.($total - 1))
        ->and($after->name)->toBe($format === 'csv' ? "'=1+1" : '=1+1')
        ->and($after->phone)->toBe($company->phone);
})->with(['xlsx', 'csv']);

it('updates only the company whose phone was changed in the exported file, in update only', function () {
    Storage::disk('local')->put('company-imports/exported.xlsx', ($this->exportAll)());
    $rows = TextPreservingExcelReader::create(Storage::disk('local')->path('company-imports/exported.xlsx'), 'xlsx')->noHeaderRow()->getRows()->values()->all();
    $phoneColumn = array_search('phone', $rows[0], true);
    $target = Company::forOrganization($this->org->id)->findOrFail($rows[1][0]);
    $snapshots = fn (): array => Company::forOrganization($this->org->id)->orderBy('id')->get()->mapWithKeys(fn (Company $company): array => [$company->id => companyRoundTripSnapshot($company)])->all();
    $before = $snapshots();
    $rows[1][$phoneColumn] = '+33 9 99 99 99 99';
    $path = makeXlsx($rows);
    DatabaseNotification::query()->delete();

    ProcessCompanyImportJob::dispatchSync($path, $this->org->id, $this->user->id, ImportMode::UpdateOnly);

    $before[$target->id]['phone'] = '+33 9 99 99 99 99';

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toStartWith('Created: 0 | Updated: '.count($before).' | Failed: 0')
        ->and($snapshots())->toEqual($before);
});

it('changes nothing when its csv export, with joined multi-select values, is imported back in update only', function () {
    $snapshots = fn (): array => Company::forOrganization($this->org->id)->orderBy('id')->get()->mapWithKeys(fn (Company $company): array => [$company->id => companyRoundTripSnapshot($company)])->all();
    $before = $snapshots();
    $file = ($this->exportAll)('csv');
    Storage::disk('local')->put('company-imports/joined.csv', $file);
    DatabaseNotification::query()->delete();

    ProcessCompanyImportJob::dispatchSync('company-imports/joined.csv', $this->org->id, $this->user->id, ImportMode::UpdateOnly);

    expect(collect($before)->filter(fn (array $snapshot): bool => collect($snapshot['custom_field_values'])->contains(fn (mixed $value): bool => is_array($value) && count($value) >= 2))->isNotEmpty())->toBeTrue()
        ->and($file)->toContain('; ')
        ->and(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toStartWith('Created: 0 | Updated: '.count($before).' | Failed: 0')
        ->and($snapshots())->toEqual($before)
        ->and(Company::forOrganization($this->org->id)->count())->toBe(count($before));
});
