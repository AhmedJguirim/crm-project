<?php

use App\Enums\ImportMode;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Jobs\ProcessContactImportJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;

/**
 * SEC-20: queued exports, export downloads, queued imports and failed-rows downloads reach the security log, ids, list
 * keys, formats, modes and counts only.
 */
function dataLogSetup(): object
{
    $olga = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $acme = Organization::factory()->create(['name' => 'Acme', 'created_by' => $olga->id]);
    $acme->members()->attach($olga->id, ['role' => OrganizationRole::Owner->value]);

    $people = ['olga' => $olga];

    foreach (['ann' => OrganizationRole::Admin, 'bob' => OrganizationRole::Member, 'vera' => OrganizationRole::Viewer] as $name => $role) {
        $people[$name] = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
        $acme->members()->attach($people[$name]->id, ['role' => $role->value]);
    }

    return (object) ['acme' => $acme, 'people' => $people];
}

/**
 * @return list<array{event: string, level: string, context: array<string, mixed>}>
 */
function dataLogNamed(TestHandler $log, string $event): array
{
    return array_values(array_filter(securityLogEntries($log), fn (array $entry): bool => $entry['event'] === $event));
}

function dataLogEvents(TestHandler $log): int
{
    return count(array_filter(securityLogEntries($log), fn (array $entry): bool => str_starts_with($entry['event'], 'data.')));
}

function dataLogContexts(TestHandler $log): string
{
    return json_encode(array_column(securityLogEntries($log), 'context'), JSON_THROW_ON_ERROR);
}

function dataLogSignIn(object $test, string $who): void
{
    session()->forget('filament.notifications');
    $test->actingAs($test->people[$who]);
    Filament::setTenant($test->acme);
}

/**
 * @return array<string, mixed>
 */
function dataLogContext(object $test, string $who, array $extra): array
{
    return [...$extra, 'user_id' => $test->people[$who]->id, 'organization_id' => $test->acme->id, 'ip' => '127.0.0.1'];
}

beforeEach(function () {
    Storage::fake('local');
    $this->log = fakeSecurityLog();
    $this->setup = dataLogSetup();
    $this->people = $this->setup->people;
    $this->acme = $this->setup->acme;

    $this->annDoe = Contact::factory()->create(['organization_id' => $this->acme->id, 'name' => 'Ann Doe', 'email' => 'ann.doe@x.test']);
    $this->zedRoe = Contact::factory()->create(['organization_id' => $this->acme->id, 'name' => 'Zed Roe', 'email' => 'zed.roe@x.test']);

    $this->csv = fn (string $name = 'contacts.csv'): UploadedFile => UploadedFile::fake()->create($name, 10, 'text/csv');
});

describe('an export queued', function () {
    beforeEach(function () {
        Queue::fake();
    });

    it('writes one line for a list export', function (string $format) {
        dataLogSignIn($this, 'ann');

        Livewire::test(ListContacts::class)
            ->callAction('exportContacts', ['format' => $format])
            ->assertNotified('Export queued');

        $lines = dataLogNamed($this->log, 'data.export_queued');

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['level'])->toBe('info')
            ->and($lines[0]['context'])->toBe(dataLogContext($this, 'ann', ['list' => 'contacts', 'format' => $format, 'rows' => 2]));
    })->with(['xlsx', 'csv']);

    it('counts the selected rows of a bulk export', function () {
        dataLogSignIn($this, 'ann');

        Livewire::test(ListContacts::class)
            ->callTableBulkAction('exportSelected', [$this->zedRoe], ['format' => 'xlsx'])
            ->assertNotified('Export queued');

        $lines = dataLogNamed($this->log, 'data.export_queued');

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['context'])->toBe(dataLogContext($this, 'ann', ['list' => 'contacts', 'format' => 'xlsx', 'rows' => 1]));
    });

    it('writes the companies list key', function () {
        Company::factory()->count(2)->create(['organization_id' => $this->acme->id]);
        dataLogSignIn($this, 'ann');

        Livewire::test(ListCompanies::class)
            ->callAction('exportCompanies', ['format' => 'xlsx'])
            ->assertNotified('Export queued');

        $lines = dataLogNamed($this->log, 'data.export_queued');

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['context'])->toBe(dataLogContext($this, 'ann', ['list' => 'companies', 'format' => 'xlsx', 'rows' => 2]));
    });

    it('writes the invoices list key with the chosen format, not the ZIP extension', function () {
        Invoice::factory()->create(['organization_id' => $this->acme->id, 'contact_id' => $this->annDoe->id, 'created_by' => $this->people['olga']->id]);
        dataLogSignIn($this, 'ann');

        Livewire::test(ListInvoices::class)
            ->callAction('exportInvoices', ['format' => 'csv'])
            ->assertNotified('Export queued');

        $lines = dataLogNamed($this->log, 'data.export_queued');

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['context'])->toBe(dataLogContext($this, 'ann', ['list' => 'invoices', 'format' => 'csv', 'rows' => 1]));
    });

    it('logs the segment id and never its name', function () {
        $segment = Segment::factory()->for($this->acme)->published()->create(['name' => 'Ada VIP clients']);
        $segment->contacts()->attach([$this->annDoe->id, $this->zedRoe->id]);
        dataLogSignIn($this, 'ann');

        Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])
            ->callAction('exportMembers', ['format' => 'xlsx'])
            ->assertNotified('Export queued');

        $lines = dataLogNamed($this->log, 'data.export_queued');

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['context'])->toBe(dataLogContext($this, 'ann', ['list' => 'segment', 'format' => 'xlsx', 'rows' => 2, 'segment_id' => $segment->id]));

        foreach (['ada', 'vip', 'clients', '@'] as $forbidden) {
            expect(stripos(dataLogContexts($this->log), $forbidden))->toBeFalse();
        }
    });

    it('writes nothing when there is nothing to export', function () {
        dataLogSignIn($this, 'ann');

        Livewire::test(ListContacts::class)
            ->set('tableSearch', 'no-contact-has-this-name')
            ->callAction('exportContacts', ['format' => 'xlsx'])
            ->assertNotified('Nothing to export.');

        expect(dataLogEvents($this->log))->toBe(0);
    });

    it('writes nothing when the cap refuses the export', function () {
        dataLogSignIn($this, 'ann');

        foreach ([1, 2] as $attempt) {
            Livewire::test(ListContacts::class)->callAction('exportContacts', ['format' => 'xlsx'])->assertNotified('Export queued');
        }

        Livewire::test(ListContacts::class)->callAction('exportContacts', ['format' => 'xlsx'])->assertNotified('Export not queued');

        expect(dataLogNamed($this->log, 'data.export_queued'))->toHaveCount(2);
    });

    it('writes nothing when a viewer forges the action', function () {
        dataLogSignIn($this, 'vera');

        Livewire::test(ListContacts::class)
            ->call('mountAction', 'exportContacts', [], [])
            ->call('callMountedAction');

        Queue::assertNothingPushed();
        expect(dataLogEvents($this->log))->toBe(0);
    });
});

describe('an import queued', function () {
    beforeEach(function () {
        Queue::fake();
    });

    it('writes one line per contact import with the chosen mode', function (ImportMode $mode) {
        dataLogSignIn($this, 'ann');

        Livewire::test(ListContacts::class)
            ->callAction('importContacts', ['file' => ($this->csv)(), 'mode' => $mode->value])
            ->assertNotified('Import queued');

        $lines = dataLogNamed($this->log, 'data.import_queued');

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['level'])->toBe('info')
            ->and($lines[0]['context'])->toBe(dataLogContext($this, 'ann', ['list' => 'contacts', 'mode' => $mode->value]));
    })->with([ImportMode::CreateOnly, ImportMode::CreateAndUpdate]);

    it('writes one line per company import with the chosen mode', function () {
        dataLogSignIn($this, 'ann');

        Livewire::test(ListCompanies::class)
            ->callAction('importCompanies', ['file' => ($this->csv)('companies.csv'), 'mode' => ImportMode::UpdateOnly->value])
            ->assertNotified('Import queued');

        $lines = dataLogNamed($this->log, 'data.import_queued');

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['context'])->toBe(dataLogContext($this, 'ann', ['list' => 'companies', 'mode' => 'update']));
    });

    it('logs the default mode when the form sent none', function () {
        dataLogSignIn($this, 'ann');

        Livewire::test(ListContacts::class)
            ->callAction('importContacts', ['file' => ($this->csv)()])
            ->assertNotified('Import queued');

        expect(dataLogNamed($this->log, 'data.import_queued')[0]['context']['mode'])->toBe('create');
    });

    it('writes nothing for an unsupported file or a refused import', function () {
        dataLogSignIn($this, 'ann');

        Livewire::test(ListContacts::class)
            ->callAction('importContacts', ['file' => UploadedFile::fake()->create('contacts.xls', 10, 'application/vnd.ms-excel')])
            ->assertNotified('Unsupported file type');

        expect(dataLogEvents($this->log))->toBe(0);

        foreach ([1, 2] as $attempt) {
            Livewire::test(ListContacts::class)->callAction('importContacts', ['file' => ($this->csv)()])->assertNotified('Import queued');
        }

        Livewire::test(ListContacts::class)->callAction('importContacts', ['file' => ($this->csv)()])->assertNotified('Import not queued');

        expect(dataLogNamed($this->log, 'data.import_queued'))->toHaveCount(2);
    });

    it('writes nothing when a member forges the import actions', function () {
        dataLogSignIn($this, 'bob');

        Livewire::test(ListContacts::class)->call('mountAction', 'importContacts', [], [])->call('callMountedAction');
        Livewire::test(ListCompanies::class)->call('mountAction', 'importCompanies', [], [])->call('callMountedAction');

        Queue::assertNothingPushed();
        expect(dataLogEvents($this->log))->toBe(0);
    });
});

describe('an export download', function () {
    beforeEach(function () {
        $this->stored = function (string $prefix = 'contacts', string $extension = 'xlsx'): string {
            $file = "{$prefix}-o{$this->acme->id}-u{$this->people['ann']->id}-".Str::random(40).".{$extension}";
            Storage::disk('local')->put("exports/{$file}", 'file contents');

            return $file;
        };

        $this->link = fn (array $query, ?string $who = 'ann'): string => URL::temporarySignedRoute(
            'exports.download',
            now()->addDays(7),
            ['user' => $this->people[$who]->id, 'organization' => $this->acme->id, ...$query],
        );
    });

    it('writes one line per download, with no file name', function () {
        $file = ($this->stored)();
        $url = ($this->link)(['file' => $file, 'name' => 'contacts-2026-11-18.xlsx']);

        $this->actingAs($this->people['ann'])->get($url)->assertOk()->assertDownload('contacts-2026-11-18.xlsx');
        $this->actingAs($this->people['ann'])->get($url)->assertOk();

        $lines = dataLogNamed($this->log, 'data.export_downloaded');

        expect($lines)->toHaveCount(2)
            ->and($lines[0]['level'])->toBe('info')
            ->and($lines[0]['context'])->toBe([
                'list' => 'contacts',
                'format' => 'xlsx',
                'file_organization_id' => $this->acme->id,
                'user_id' => $this->people['ann']->id,
                'organization_id' => null,
                'ip' => '127.0.0.1',
            ]);

        foreach ([$file, substr($file, -45, 40), 'contacts-2026-11-18'] as $forbidden) {
            expect(str_contains(dataLogContexts($this->log), $forbidden))->toBeFalse();
        }
    });

    it('logs zip for an invoices archive', function () {
        $file = ($this->stored)('invoices', 'zip');

        $this->actingAs($this->people['ann'])->get(($this->link)(['file' => $file]))->assertOk();

        $lines = dataLogNamed($this->log, 'data.export_downloaded');

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['context']['list'])->toBe('invoices')
            ->and($lines[0]['context']['format'])->toBe('zip')
            ->and($lines[0]['context']['file_organization_id'])->toBe($this->acme->id);
    });

    it('writes nothing for a refused download', function (string $case, int $status) {
        $file = ($this->stored)();
        $url = ($this->link)(['file' => $file]);

        if ($case === 'demoted') {
            $this->acme->members()->updateExistingPivot($this->people['ann']->id, ['role' => OrganizationRole::Viewer->value]);
        }

        if ($case === 'deleted file') {
            Storage::disk('local')->delete("exports/{$file}");
        }

        $response = $this->actingAs($this->people[$case === 'another user' ? 'bob' : 'ann'])
            ->get($case === 'tampered signature' ? $url.'0' : $url);

        $response->assertStatus($status);
        expect(dataLogEvents($this->log))->toBe(0);
    })->with([
        'another user' => ['another user', 403],
        'tampered signature' => ['tampered signature', 403],
        'demoted to viewer' => ['demoted', 403],
        'deleted file' => ['deleted file', 404],
    ]);
});

describe('a failed rows download', function () {
    beforeEach(function () {
        Storage::disk('local')->put('contact-imports/ann.csv', "name,email,phone,tags\nBroken,not-an-email,,\n");
        ProcessContactImportJob::dispatchSync('contact-imports/ann.csv', $this->acme->id, $this->people['ann']->id);
        $this->log->clear();

        $this->failedUrl = DatabaseNotification::where('notifiable_id', $this->people['ann']->id)->get()
            ->map(fn (DatabaseNotification $notification): ?string => $notification->data['actions'][0]['url'] ?? null)
            ->filter()
            ->first();
    });

    it('writes one line for the importer and none for a refused user', function () {
        $this->actingAs($this->people['bob'])->get($this->failedUrl)->assertForbidden();

        expect(dataLogEvents($this->log))->toBe(0);

        $this->actingAs($this->people['ann'])->get($this->failedUrl)->assertOk();

        $lines = dataLogNamed($this->log, 'data.import_failed_rows_downloaded');

        expect($lines)->toHaveCount(1)
            ->and($lines[0]['level'])->toBe('info')
            ->and($lines[0]['context'])->toBe([
                'file_organization_id' => $this->acme->id,
                'user_id' => $this->people['ann']->id,
                'organization_id' => null,
                'ip' => '127.0.0.1',
            ]);

        $this->actingAs($this->people['bob'])->get($this->failedUrl)->assertForbidden();
        expect(dataLogNamed($this->log, 'data.import_failed_rows_downloaded'))->toHaveCount(1)
            ->and(str_contains(dataLogContexts($this->log), 'failed-'))->toBeFalse();
    });

    it('writes nothing when the file was deleted', function () {
        foreach (Storage::disk('local')->files('contact-imports') as $file) {
            Storage::disk('local')->delete($file);
        }

        $this->actingAs($this->people['ann'])->get($this->failedUrl)->assertNotFound();

        expect(dataLogEvents($this->log))->toBe(0);
    });
});
