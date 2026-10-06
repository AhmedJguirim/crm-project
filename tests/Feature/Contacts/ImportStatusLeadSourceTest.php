<?php

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use App\Services\ContactImportService;
use App\Services\ContactImportTemplate;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->import = function (string $csv): DatabaseNotification {
        $path = 'contact-imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);

        DatabaseNotification::query()->delete();
        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    };
    $this->contact = fn (string $email): Contact => Contact::forOrganization($this->org->id)->where('email', $email)->firstOrFail();
    $this->failedRowsFile = fn (): string => Storage::disk('local')->get(
        collect(Storage::disk('local')->files('contact-imports'))->first(fn (string $file): bool => str_contains($file, 'failed-'))
    );
});

describe('status and lead source', function () {
    it('are read by label or by stored value', function (string $status, string $source, ContactStatus $storedStatus, ?LeadSource $storedSource) {
        $notification = ($this->import)("name,email,status,lead source\nAnn,ann@x.test,\"{$status}\",\"{$source}\"\n");

        expect($notification->data['body'])->toBe('Imported: 1 | Failed: 0')
            ->and(($this->contact)('ann@x.test')->only(['status', 'lead_source']))->toBe(['status' => $storedStatus, 'lead_source' => $storedSource]);
    })->with([
        'labels' => ['Active Client', 'LinkedIn', ContactStatus::ActiveClient, LeadSource::LinkedIn],
        'stored values' => ['active_client', 'cold_outreach', ContactStatus::ActiveClient, LeadSource::ColdOutreach],
        'case and spaces' => [' PROSPECT', ' referral', ContactStatus::Prospect, LeadSource::Referral],
        'label of a two-word source' => ['Past Client', 'cold outreach', ContactStatus::PastClient, LeadSource::ColdOutreach],
        'blank cells' => ['', '', ContactStatus::Lead, null],
    ]);

    it('default to Lead and nothing when the columns are missing', function () {
        ($this->import)("name,email\nBob,bob@x.test\n");

        expect(($this->contact)('bob@x.test')->only(['status', 'lead_source']))->toBe(['status' => ContactStatus::Lead, 'lead_source' => null]);
    });

    it('fail the row when the value is unknown', function (string $column, string $value) {
        $notification = ($this->import)("name,email,{$column}\nAnn,ann@x.test,{$value}\n");

        expect($notification->data['title'])->toBe('Import complete with errors')
            ->and($notification->data['body'])->toContain("Row 1: Invalid value for field '{$column}': {$value}")
            ->and(Contact::count())->toBe(0);
    })->with([
        ['status', 'VIP'],
        ['lead source', 'TikTok'],
    ]);

    it('do not mix up a status written in the lead source column', function () {
        $notification = ($this->import)("name,email,lead source\nAnn,ann@x.test,Prospect\n");

        expect($notification->data['body'])->toContain("Invalid value for field 'lead source': Prospect");
    });
});

describe('headers', function () {
    it('match ignoring case and surrounding spaces', function () {
        CustomField::factory()->for($this->org)->create(['name' => 'Hourly Rate', 'type' => 'number', 'unique' => false]);

        $notification = ($this->import)(" Name ,EMAIL,Phone,TAGS,Status,Lead Source,hourly rate \nAnn,ann@x.test,+33612345678,VIP,Prospect,Referral,50\n");

        $contact = ($this->contact)('ann@x.test');

        expect($notification->data['title'])->toBe('Import complete')
            ->and($notification->data['body'])->toBe('Imported: 1 | Failed: 0')
            ->and($contact->name)->toBe('Ann')
            ->and($contact->phone)->toBe('+33612345678')
            ->and($contact->status)->toBe(ContactStatus::Prospect)
            ->and($contact->lead_source)->toBe(LeadSource::Referral)
            ->and($contact->customFieldValue(CustomField::where('name', 'Hourly Rate')->sole()->key))->toEqual(50)
            ->and($contact->tags()->withoutGlobalScopes()->pluck('name')->all())->toBe(['VIP']);
    });

    it('are mapped to the canonical column, keeping unknown ones as they are', function () {
        CustomField::factory()->for($this->org)->create(['name' => 'Hourly Rate', 'type' => 'number', 'unique' => false]);

        expect((new ContactImportService($this->org->id))->canonicalHeaders([' Name ', 'EMAIL', ' LEAD SOURCE', 'hourly RATE ', 'Favourite colour', ' _ERROR ', '']))
            ->toBe(['name', 'email', 'lead source', 'Hourly Rate', 'Favourite colour', '_error', '']);
    });

    it('keep the original headers in the failed rows file, which imports again', function () {
        $notification = ($this->import)("Name,EMAIL\nAnn,not-an-email\n");

        expect($notification->data['title'])->toBe('Import complete with errors');

        $file = ($this->failedRowsFile)();

        expect($file)->toContain("_row_number,_error,Name,EMAIL\n")
            ->and($file)->toContain('Invalid email: not-an-email');

        $corrected = ($this->import)(str_replace('not-an-email', 'ann@x.test', $file));

        expect($corrected->data['body'])->toBe('Imported: 1 | Failed: 0')
            ->and(($this->contact)('ann@x.test')->name)->toBe('Ann');
    });

    it('write the failed rows columns once when a failed rows file fails again, so it imports after the fix', function () {
        ($this->import)("name,email\nAnn,not-an-email\n");
        $first = ($this->failedRowsFile)();
        Storage::disk('local')->delete(collect(Storage::disk('local')->files('contact-imports'))->filter(fn (string $file): bool => str_contains($file, 'failed-'))->all());

        ($this->import)($first);
        $second = ($this->failedRowsFile)();

        expect($first)->toContain("_row_number,_error,name,email\n")
            ->and($second)->toContain("_row_number,_error,name,email\n")
            ->and($second)->not->toContain('_row_number,_error,_row_number');

        $corrected = ($this->import)(str_replace('not-an-email', 'ann@x.test', $second));

        expect($corrected->data['body'])->toBe('Imported: 1 | Failed: 0')
            ->and(($this->contact)('ann@x.test')->name)->toBe('Ann');
    });

    it('import a failed rows file that carries its columns twice', function () {
        $notification = ($this->import)("_row_number,_error,_row_number,_error,name,email\n3,Invalid email: x,3,Invalid email: x,Ann,ann@x.test\n");

        expect($notification->data['body'])->toBe('Imported: 1 | Failed: 0')
            ->and(($this->contact)('ann@x.test')->name)->toBe('Ann');
    });

    it('still report unknown columns', function () {
        $notification = ($this->import)("name,email,Favourite colour\nAnn,ann@x.test,blue\n");

        expect($notification->data['title'])->toBe('Import complete, some columns were ignored')
            ->and($notification->data['body'])->toContain('Ignored columns (no matching field): "Favourite colour"');
    });

    it('fail the whole file when two headers are the same column', function (string $headers, string $column) {
        $notification = ($this->import)("{$headers}\nAnn,ann@x.test,Ann\n");

        expect($notification->data['title'])->toBe('Import failed')
            ->and($notification->data['body'])->toBe("Two columns are named \"{$column}\". Keep only one and import the file again.")
            ->and(Contact::count())->toBe(0);
    })->with([
        'email twice' => ['email,Email ,name', 'email'],
        'status twice' => ['name,email,status, STATUS', 'status'],
    ]);

    it('fail the whole file when two headers are the same custom field', function () {
        CustomField::factory()->for($this->org)->create(['name' => 'Hourly Rate', 'type' => 'number', 'unique' => false]);

        $notification = ($this->import)("name,email,Hourly Rate,hourly rate\nAnn,ann@x.test,1,2\n");

        expect($notification->data['body'])->toBe('Two columns are named "Hourly Rate". Keep only one and import the file again.');
    });

    it('do not count repeated unknown or empty headers as a duplicate', function () {
        $service = new ContactImportService($this->org->id);

        expect($service->duplicatedColumn(['name', 'email', 'Notes', 'Notes', '', '']))->toBeNull()
            ->and($service->duplicatedColumn(['_row_number', '_error', '_row_number', '_error', 'name', 'email']))->toBeNull()
            ->and($service->duplicatedColumn(['name', ' NAME ', 'email']))->toBe('name');
    });
});

describe('the template', function () {
    it('has the new columns and imports unchanged', function () {
        $path = ContactImportTemplate::forOrganization($this->org->id)->writeXlsx();
        $storedPath = 'contact-imports/template-'.uniqid().'.xlsx';
        Storage::disk('local')->put($storedPath, file_get_contents($path));
        unlink($path);

        $template = ContactImportTemplate::forOrganization($this->org->id);

        expect(array_slice($template->headers(), 0, 8))->toBe(['name', 'email', 'phone', 'tags', 'status', 'lead source', 'company', 'company website'])
            ->and(array_slice($template->exampleRow(), 4, 4))->toBe(['Lead', 'Referral', 'Acme Corp', 'acme.com']);

        DatabaseNotification::query()->delete();
        ProcessContactImportJob::dispatchSync($storedPath, $this->org->id, $this->user->id);

        expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe("Imported: 1 | Failed: 0\n\nCompanies created: 1")
            ->and(($this->contact)('john@example.com')->only(['name', 'status', 'lead_source']))
            ->toBe(['name' => 'John Doe', 'status' => ContactStatus::Lead, 'lead_source' => LeadSource::Referral])
            ->and(($this->contact)('john@example.com')->companies()->withoutGlobalScope('organization')->get()->map->only(['name', 'website', 'domain'])->all())
            ->toBe([['name' => 'Acme Corp', 'website' => 'acme.com', 'domain' => 'acme.com']]);
    });
});

it('reserves every base column', function () {
    expect(ContactImportService::RESERVED_COLUMNS)->toBe(['name', 'email', 'phone', 'tags', 'status', 'lead source', 'company', 'company website', 'company type', 'company phone', 'company industry', 'company employees', 'company annual revenue', 'company street', 'company city', 'company zip', 'company country']);
});
