<?php

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Jobs\ExportContactsJob;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\User;
use Database\Seeders\ItConsultingSeeder;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

    $this->exportAll = function (): string {
        $ids = Contact::forOrganization($this->org->id)->orderBy('id')->pluck('id')->all();

        ExportContactsJob::dispatchSync($ids, $this->org->id, $this->user->id, 'xlsx', 'contacts-2026-10-06.xlsx');

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

    $this->importFile = function (string $contents): DatabaseNotification {
        Storage::disk('local')->put('contact-imports/round-trip.xlsx', $contents);
        DatabaseNotification::query()->delete();

        ProcessContactImportJob::dispatchSync('contact-imports/round-trip.xlsx', $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    };
});

it('recreates a deleted contact from the exported file, and refuses the others as existing', function () {
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
    $file = ($this->exportAll)();

    ($this->removeFromDatabase)($contact);
    expect(Contact::forOrganization($this->org->id)->count())->toBe($total - 1);

    $notification = ($this->importFile)($file);
    $after = roundTripSnapshot(Contact::forOrganization($this->org->id)->where('email', $contact->email)->sole());

    expect($notification->data['body'])->toContain('Imported: 1 | Failed: '.($total - 1))
        ->and($notification->data['body'])->toContain('already exists.')
        ->and($after)->toEqual($before);
});

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
