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
        ->and($before['annual_revenue'])->not->toBeNull();

    ($this->removeFromDatabase)($company);
    expect(Company::forOrganization($this->org->id)->count())->toBe($total - 1);

    $notification = ($this->importFile)($file, $format);
    $after = companyRoundTripSnapshot(Company::forOrganization($this->org->id)->where('name', $before['name'])->sole());

    expect($notification->data['body'])->toContain('Imported: 1 | Failed: '.($total - 1))
        ->and($notification->data['body'])->toContain('already exists.')
        ->and($after)->toEqual($before);
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
