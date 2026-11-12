<?php

use App\Exceptions\UnreadableImportFileException;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\ProcessCompanyImportJob;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\SyncSegmentMembership;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Segment;
use App\Models\User;
use App\Services\ContactImportFileReader;
use App\Support\CsvDialect;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();

    $this->otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->otherOrg = $this->otherUser->personalOrganization();
    $this->otherContact = Contact::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Untouched']);
    $this->otherCompany = Company::factory()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Untouched Co']);

    $this->store = function (string $content): string {
        $path = 'contact-imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $content);

        return $path;
    };

    $this->runImport = function (string $kind, string $path): DatabaseNotification {
        DatabaseNotification::query()->delete();

        $kind === 'contacts'
            ? ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id)
            : ProcessCompanyImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    };

    $this->rowsOf = fn (string $kind, int $count, string $separator = "\n"): string => ($kind === 'contacts' ? "name,email\n" : "name,website\n")
        .implode($separator, array_map(
            fn (int $i): string => $kind === 'contacts' ? "P{$i},p{$i}@example.com" : "Co {$i},co{$i}.example.com",
            range(1, $count),
        ));

    $this->mismatchedRows = fn (string $kind, int $count): string => ($kind === 'contacts' ? "name,email\n" : "name,website\n")
        .implode("\n", array_fill(0, $count, 'x,y,z'));

    $this->failedRowsLines = function (): array {
        $file = collect(Storage::disk('local')->files('contact-imports'))->first(fn (string $file): bool => str_starts_with(basename($file), 'failed-'));

        return preg_split('/\R/', trim(Storage::disk('local')->get($file)));
    };

    $this->count = fn (string $kind): int => $kind === 'contacts'
        ? Contact::forOrganization($this->org->id)->count()
        : Company::forOrganization($this->org->id)->count();
});

const TOO_MANY_ROWS_MESSAGE = 'This file has more than 20,000 rows. Split it into files of 20,000 rows or fewer and import them one after the other.';

it('refuses a file of 20,001 rows and writes nothing', function (string $kind) {
    Queue::fake([SyncSegmentMembership::class]);
    Segment::factory()->for($this->org)->published()->create();
    $path = ($this->store)(($this->rowsOf)($kind, 20001));

    $notification = ($this->runImport)($kind, $path);

    expect(($this->count)($kind))->toBe(0)
        ->and($notification->data['status'])->toBe('danger')
        ->and($notification->data['title'])->toBe('Import failed')
        ->and($notification->data['body'])->toBe(TOO_MANY_ROWS_MESSAGE)
        ->and($notification->data['actions'])->toBe([])
        ->and(Storage::disk('local')->exists($path))->toBeFalse()
        ->and(Contact::forOrganization($this->otherOrg->id)->count())->toBe(1)
        ->and(Company::forOrganization($this->otherOrg->id)->count())->toBe(1);
    Queue::assertNotPushed(SyncSegmentMembership::class);
})->with(['contacts', 'companies']);

it('does not let blank lines hide rows from the count', function () {
    $path = ($this->store)(($this->rowsOf)('contacts', 20001, "\n\n"));

    $notification = ($this->runImport)('contacts', $path);

    expect($notification->data['title'])->toBe('Import failed')
        ->and($notification->data['body'])->toBe(TOO_MANY_ROWS_MESSAGE)
        ->and(($this->count)('contacts'))->toBe(0);
});

it('counts the rows the reader yields, whatever the file format', function () {
    app()->bind(ContactImportFileReader::class, fn () => new class extends ContactImportFileReader
    {
        public function rows(string $absolutePath, string $extension, ?CsvDialect $dialect = null): Generator
        {
            yield ['name', 'email'];

            for ($i = 1; $i <= ContactImportFileReader::MAX_DATA_ROWS + 1; $i++) {
                yield ["P{$i}", "p{$i}@example.com"];
            }
        }
    });
    $path = ($this->store)("name,email\n");

    $notification = ($this->runImport)('contacts', $path);

    expect($notification->data['body'])->toBe(TOO_MANY_ROWS_MESSAGE)
        ->and(($this->count)('contacts'))->toBe(0);
});

it('keeps only 1,000 failed rows and still counts every failure', function (string $kind) {
    $notification = ($this->runImport)($kind, ($this->store)(($this->mismatchedRows)($kind, 1003)));

    $lines = ($this->failedRowsLines)();

    expect($notification->data['title'])->toBe('Import complete with errors')
        ->and($notification->data['body'])->toContain('Imported: 0 | Failed: 1003')
        ->and($notification->data['body'])->toContain('Only the first 1,000 failed rows are in the failed rows file. Failed rows not listed: 3.')
        ->and($notification->data['body'])->toEndWith("Row 1: Column count mismatch.\nRow 2: Column count mismatch.\nRow 3: Column count mismatch.\nRow 4: Column count mismatch.\nRow 5: Column count mismatch.")
        ->and($notification->data['actions'][0]['name'])->toBe('downloadFailedRows')
        ->and($lines)->toHaveCount(1001)
        ->and($lines[1000])->toStartWith('1000,')
        ->and(($this->count)($kind))->toBe(0);
})->with(['contacts', 'companies']);

it('adds no not-listed line at exactly 1,000 failed rows', function () {
    $notification = ($this->runImport)('contacts', ($this->store)(($this->mismatchedRows)('contacts', 1000)));

    expect($notification->data['body'])->toContain('Failed: 1000')
        ->and($notification->data['body'])->not->toContain('Failed rows not listed')
        ->and(($this->failedRowsLines)())->toHaveCount(1001);
});

it('reports the real failed count when the file becomes unreadable after more than 1,000 failed rows', function (string $kind, array $header, array $failingRow) {
    Queue::fake([SyncSegmentMembership::class]);
    $rows = [$header, ...array_fill(0, 1002, $failingRow)];

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

    $notification = ($this->runImport)($kind, ($this->store)(implode(',', $header)."\n"));

    expect($notification->data['title'])->toBe('Import stopped partway')
        ->and($notification->data['body'])->toContain('Imported: 0 | Failed: 1002')
        ->and($notification->data['body'])->toContain('Failed rows not listed: 2.')
        ->and($notification->data['actions'][0]['name'])->toBe('downloadFailedRows')
        ->and(($this->failedRowsLines)())->toHaveCount(1001)
        ->and(($this->count)($kind))->toBe(0);
})->with([
    'contacts' => ['contacts', ['name', 'email', 'phone', 'tags'], ['Bad', 'not-an-email', '', '']],
    'companies' => ['companies', ['name', 'website'], ['x', 'y', 'z']],
]);

it('states both limits in the upload form', function (string $listPage, string $action) {
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    Livewire::test($listPage)
        ->mountAction($action)
        ->assertSchemaComponentExists('file', 'mountedActionSchema0', function (FileUpload $field): bool {
            $helperText = collect($field->getChildComponents($field::BELOW_CONTENT_SCHEMA_KEY))->map(fn ($component): string => (string) $component->getContent())->implode(' ');

            return str_contains($helperText, 'Up to 10 MB and 20,000 rows.');
        });
})->with([
    'contacts' => [ListContacts::class, 'importContacts'],
    'companies' => [ListCompanies::class, 'importCompanies'],
]);
