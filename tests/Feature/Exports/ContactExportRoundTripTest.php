<?php

use App\Enums\ContactStatus;
use App\Enums\ImportMode;
use App\Enums\LeadSource;
use App\Jobs\ExportContactsJob;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\User;
use App\Services\Imports\TextPreservingExcelReader;
use Database\Seeders\ItConsultingSeeder;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelReader;

/**
 * What the import has to give back for a contact: the stored fields, the tag names, the companies and the custom field values.
 *
 * @return array<string, mixed>
 */
function roundTripSnapshot(Contact $contact): array
{
    return [
        'name' => $contact->name,
        'email' => $contact->email,
        'phone' => $contact->phone,
        'status' => $contact->status,
        'lead_source' => $contact->lead_source,
        'tags' => $contact->tags()->pluck('name')->sort()->values()->all(),
        'companies' => $contact->companies()->pluck('name')->sort()->values()->all(),
        'owner_id' => $contact->owner_id,
        'custom_field_values' => collect($contact->custom_field_values)->sortKeys()->all(),
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
        $ids = Contact::forOrganization($this->org->id)->orderBy('id')->pluck('id')->all();

        ExportContactsJob::dispatchSync($ids, $this->org->id, $this->user->id, $format, "contacts-2026-10-06.{$format}");

        return Storage::disk('local')->get(Storage::disk('local')->files('exports')[0]);
    };

    $this->removeFromDatabase = function (Contact $contact): void {
        DB::transaction(function () use ($contact): void {
            foreach (['contact_tag', 'company_contact', 'contact_segment'] as $pivot) {
                DB::table($pivot)->where('contact_id', $contact->id)->delete();
            }

            DB::table('contacts')->where('id', $contact->id)->delete();
        });
    };

    $this->importFile = function (string $contents, string $extension = 'xlsx'): DatabaseNotification {
        Storage::disk('local')->put("contact-imports/round-trip.{$extension}", $contents);
        DatabaseNotification::query()->delete();

        ProcessContactImportJob::dispatchSync("contact-imports/round-trip.{$extension}", $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    };
});

it('recreates a deleted contact from the exported file, and refuses the others as existing', function (string $format) {
    $contact = Contact::forOrganization($this->org->id)
        ->whereNotNull('phone')
        ->whereHas('tags')
        ->whereHas('companies')
        ->orderBy('id')
        ->get()
        ->first(fn (Contact $contact): bool => count($contact->custom_field_values) >= 3);

    expect($contact)->not->toBeNull();

    $contact->update(['status' => ContactStatus::ActiveClient, 'lead_source' => LeadSource::Referral]);
    $before = roundTripSnapshot($contact->fresh());
    $total = Contact::forOrganization($this->org->id)->count();
    $file = ($this->exportAll)($format);

    expect($before['owner_id'])->toBe($this->user->id);

    ($this->removeFromDatabase)($contact);
    expect(Contact::forOrganization($this->org->id)->count())->toBe($total - 1);

    $notification = ($this->importFile)($file, $format);
    $after = roundTripSnapshot(Contact::forOrganization($this->org->id)->where('email', $contact->email)->sole());

    expect($notification->data['body'])->toContain('Imported: 1 | Failed: '.($total - 1))
        ->and($notification->data['body'])->toContain('already exists.')
        ->and($after)->toEqual($before);
})->with(['xlsx', 'csv']);

it('gives back an unowned contact as unowned, and fails the same rows again from the failed rows file', function (string $format) {
    $owned = Contact::forOrganization($this->org->id)->where('owner_id', $this->user->id)->orderBy('id')->firstOrFail();
    $unowned = Contact::forOrganization($this->org->id)->where('email', 'amara.diallo@innotech-sa.com')->firstOrFail();
    $owned->update(['status' => ContactStatus::ActiveClient, 'lead_source' => LeadSource::Referral]);
    $unowned->update(['status' => ContactStatus::Lead, 'lead_source' => LeadSource::Referral]);
    $before = [roundTripSnapshot($owned), roundTripSnapshot($unowned)];
    $total = Contact::forOrganization($this->org->id)->count();
    $file = ($this->exportAll)($format);

    ($this->removeFromDatabase)($owned);
    ($this->removeFromDatabase)($unowned);

    $notification = ($this->importFile)($file, $format);
    $after = [
        roundTripSnapshot(Contact::forOrganization($this->org->id)->where('email', $owned->email)->sole()),
        roundTripSnapshot(Contact::forOrganization($this->org->id)->where('email', $unowned->email)->sole()),
    ];

    expect($before[1]['owner_id'])->toBeNull()
        ->and($notification->data['body'])->toContain('Imported: 2 | Failed: '.($total - 2))
        ->and($after)->toEqual($before);

    $failedFiles = fn (): array => collect(Storage::disk('local')->files('contact-imports'))->filter(fn (string $path): bool => str_contains($path, 'failed-'))->values()->all();
    $errors = fn (string $path): array => SimpleExcelReader::create(Storage::disk('local')->path($path), 'csv')->getRows()->map(fn (array $row): array => [$row['email'], $row['_error']])->sortBy(0)->values()->all();
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

it('gives back a contact whose name starts with an equals sign', function () {
    $contact = Contact::forOrganization($this->org->id)->whereNotNull('phone')->orderBy('id')->firstOrFail();
    $contact->update(['name' => '=1+1']);
    $total = Contact::forOrganization($this->org->id)->count();
    $file = ($this->exportAll)();

    ($this->removeFromDatabase)($contact);

    $notification = ($this->importFile)($file);
    $after = Contact::forOrganization($this->org->id)->where('email', $contact->email)->sole();

    expect($notification->data['body'])->toContain('Imported: 1 | Failed: '.($total - 1))
        ->and($after->name)->toBe('=1+1')
        ->and($after->phone)->toBe($contact->phone);
});

it('updates only the contact whose phone was changed in the exported file, in update only', function () {
    Storage::disk('local')->put('contact-imports/exported.xlsx', ($this->exportAll)());
    $rows = TextPreservingExcelReader::create(Storage::disk('local')->path('contact-imports/exported.xlsx'), 'xlsx')->noHeaderRow()->getRows()->values()->all();
    $phoneColumn = array_search('phone', $rows[0], true);
    $target = Contact::forOrganization($this->org->id)->findOrFail($rows[1][0]);
    $before = Contact::forOrganization($this->org->id)->orderBy('id')->get()->mapWithKeys(fn (Contact $contact): array => [$contact->id => roundTripSnapshot($contact)])->all();
    $rows[1][$phoneColumn] = '+33 9 99 99 99 99';
    $path = makeXlsx($rows);
    DatabaseNotification::query()->delete();

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id, ImportMode::UpdateOnly);

    $after = Contact::forOrganization($this->org->id)->orderBy('id')->get()->mapWithKeys(fn (Contact $contact): array => [$contact->id => roundTripSnapshot($contact)])->all();
    $before[$target->id]['phone'] = '+33 9 99 99 99 99';

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toStartWith('Created: 0 | Updated: '.count($before).' | Failed: 0')
        ->and($after)->toEqual($before);
});
