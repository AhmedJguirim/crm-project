<?php

use App\Exceptions\UnreadableImportFileException;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Services\ContactImportService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    CustomField::factory()->for($this->org)->create(['name' => 'Daily Rate (€)', 'type' => 'number', 'unique' => false]);
    CustomField::factory()->for($this->org)->create(['name' => 'Old Code', 'type' => 'text', 'unique' => false])->delete();

    $this->importCsv = function (string $content): DatabaseNotification {
        $path = 'contact-imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $content);

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->latest('created_at')->firstOrFail();
    };
});

it('reports an unknown column and still imports', function () {
    $notification = ($this->importCsv)("name,email,Hourly Rate (€)\nAnn,ann@example.com,50\n");

    expect(Contact::forOrganization($this->org->id)->count())->toBe(1)
        ->and($notification->data['title'])->toBe('Import complete, some columns were ignored')
        ->and($notification->data['status'])->toBe('warning')
        ->and($notification->data['body'])->toBe("Imported: 1 | Failed: 0\n\nIgnored columns (no matching field): \"Hourly Rate (€)\"");
});

it('says when the column belongs to a deleted field', function () {
    $notification = ($this->importCsv)("name,email,Old Code\nAnn,ann@example.com,X1\n");

    expect($notification->data['body'])->toContain('"Old Code" (deleted field)');
});

it('does not warn when every column is used', function () {
    $notification = ($this->importCsv)("name,email,phone,tags,Daily Rate (€)\nAnn,ann@example.com,,,50\n");

    expect($notification->data['title'])->toBe('Import complete')
        ->and($notification->data['status'])->toBe('success')
        ->and($notification->data['body'])->not->toContain('Ignored columns');
});

it('does not warn about the columns of the failed rows file', function () {
    $notification = ($this->importCsv)("_row_number,_error,name,email\n3,Invalid email,Ann,ann@example.com\n");

    expect($notification->data['body'])->not->toContain('Ignored columns');
});

it('does not report blank headers', function () {
    $path = makeXlsx([['name', '', 'email'], ['Ann', '', 'ann@example.com']]);
    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();

    expect($notification->data['body'])->not->toContain('Ignored columns')
        ->and($notification->data['title'])->toBe('Import complete');
});

it('keeps matching headers exactly and explains a miss', function () {
    $notification = ($this->importCsv)("Name,email\nAnn,ann@example.com\n");

    expect($notification->data['title'])->toBe('Import complete with errors')
        ->and($notification->data['body'])->toContain('Ignored columns (no matching field): "Name"')
        ->and($notification->data['body'])->toContain('Row 1: Name is required.');
});

it('puts the ignored columns between the counts and the failed rows', function () {
    $notification = ($this->importCsv)("name,email,Extra\nAnn,not-an-email,1\n");

    expect($notification->data['body'])->toBe("Imported: 0 | Failed: 1\n\nIgnored columns (no matching field): \"Extra\"\n\nRow 1: Invalid email: not-an-email");
});

it('lists at most ten columns', function () {
    $extra = collect(range(1, 12))->map(fn (int $number): string => "c{$number}");
    $notification = ($this->importCsv)('name,email,'.$extra->implode(',')."\nAnn,ann@example.com,".$extra->map(fn () => 'x')->implode(',')."\n");

    expect($notification->data['body'])->toContain('"c1", "c2", "c3", "c4", "c5", "c6", "c7", "c8", "c9", "c10", … and 2 more')
        ->and($notification->data['body'])->not->toContain('"c11"');
});

it('counts a header that appears twice once', function () {
    $notification = ($this->importCsv)("name,email,Extra,Extra\nAnn,ann@example.com,1,2\n");

    expect($notification->data['body'])->toContain('(no matching field): "Extra"')
        ->and($notification->data['body'])->not->toContain('"Extra", "Extra"');
});

it('is also in the notification of a file that becomes unreadable partway', function () {
    app()->bind(ContactImportFileReader::class, fn () => new class extends ContactImportFileReader
    {
        public function rows(string $absolutePath, string $extension): Generator
        {
            yield ['name', 'email', 'Hourly Rate'];
            yield ['Ann', 'ann@example.com', '50'];

            throw UnreadableImportFileException::unreadable(new RuntimeException('Corrupt file'));
        }
    });
    Storage::disk('local')->put('contact-imports/partial.csv', "name\n");

    ProcessContactImportJob::dispatchSync('contact-imports/partial.csv', $this->org->id, $this->user->id);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();

    expect($notification->data['title'])->toBe('Import stopped partway')
        ->and($notification->data['body'])->toContain('Ignored columns (no matching field): "Hourly Rate"');
});

it('keeps the meta columns of the failed rows file in sync with what the import accepts', function () {
    ($this->importCsv)("name,email\nAnn,not-an-email\n");

    $report = collect(Storage::disk('local')->files('contact-imports'))->first(fn (string $file): bool => str_contains($file, 'failed-'));
    $headers = str_getcsv(ltrim(strtok(Storage::disk('local')->get($report), "\n"), "\xEF\xBB\xBF"));

    expect(array_slice($headers, 0, 2))->toBe(ContactImportService::FAILED_ROWS_META_COLUMNS);
});

it('knows the base columns of the template', function () {
    expect(ContactImportService::BASE_COLUMNS)->toBe(['name', 'email', 'phone', 'tags']);
});
