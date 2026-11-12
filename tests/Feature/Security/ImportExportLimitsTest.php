<?php

use App\Enums\ImportMode;
use App\Enums\OrganizationRole;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Jobs\ExportCompaniesJob;
use App\Jobs\ExportContactsJob;
use App\Jobs\ExportDealsJob;
use App\Jobs\Middleware\ReleasesImportExportSlot;
use App\Jobs\Middleware\WithTenantContext;
use App\Jobs\ProcessCompanyImportJob;
use App\Jobs\ProcessContactImportJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use App\Support\Tenancy\ImportExportLimits;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

const IMPORT_USER_REFUSAL = 'You already have 2 imports waiting or running. Try again when one of them has finished.';
const IMPORT_ORGANIZATION_REFUSAL = 'This organization already has 3 imports waiting or running. Try again when one of them has finished.';
const EXPORT_USER_REFUSAL = 'You already have 2 exports waiting or being built. Try again when one of them is ready.';
const EXPORT_ORGANIZATION_REFUSAL = 'This organization already has 3 exports waiting or being built. Try again when one of them is ready.';

beforeEach(function () {
    Storage::fake('local');

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->acme = $this->owner->personalOrganization();
    $this->globex = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();

    $this->member = function (Organization $organization, OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $organization->members()->attach($user, ['role' => $role->value]);

        return $user;
    };

    $this->ann = ($this->member)($this->acme, OrganizationRole::Admin);
    $this->bob = ($this->member)($this->acme, OrganizationRole::Admin);
    $this->cid = ($this->member)($this->acme, OrganizationRole::Admin);
    $this->gus = ($this->member)($this->globex, OrganizationRole::Admin);

    $this->contact = Contact::factory()->create(['organization_id' => $this->acme->id, 'name' => 'Ann Doe', 'email' => 'ann.doe@x.test']);
    $this->globexContact = Contact::factory()->create(['organization_id' => $this->globex->id]);

    $this->csv = fn (string $name = 'contacts.csv'): UploadedFile => UploadedFile::fake()->create($name, 10, 'text/csv');

    $this->in = function (User $user, Organization $organization): void {
        session()->forget('filament.notifications');
        $this->actingAs($user);
        Filament::setTenant($organization);
    };

    $this->importContacts = function (User $user, Organization $organization, ?UploadedFile $file = null) {
        ($this->in)($user, $organization);

        return Livewire::test(ListContacts::class)->callAction('importContacts', ['file' => $file ?? ($this->csv)()]);
    };

    $this->importCompanies = function (User $user, Organization $organization) {
        ($this->in)($user, $organization);

        return Livewire::test(ListCompanies::class)->callAction('importCompanies', ['file' => ($this->csv)('companies.csv')]);
    };

    $this->exportContacts = function (User $user, Organization $organization) {
        ($this->in)($user, $organization);

        return Livewire::test(ListContacts::class)->callAction('exportContacts', ['format' => 'xlsx']);
    };

    $this->refused = fn (string $title, string $body): Notification => Notification::make()->danger()->title($title)->body($body);
});

describe('with a faked queue the jobs never end, so their slots stay held', function () {
    beforeEach(function () {
        Queue::fake();
    });

    it('refuses the third import of a user, whatever the kind, and deletes the file', function () {
        ($this->importContacts)($this->ann, $this->acme)->assertNotified('Import queued');
        ($this->importCompanies)($this->ann, $this->acme)->assertNotified('Import queued');

        ($this->importContacts)($this->ann, $this->acme, ($this->csv)('third.csv'))
            ->assertNotified(($this->refused)('Import not queued', IMPORT_USER_REFUSAL))
            ->assertNotNotified('Import queued');

        Queue::assertPushed(ProcessContactImportJob::class, 1);
        Queue::assertPushed(ProcessCompanyImportJob::class, 1);
        expect(Storage::disk('local')->allFiles('contact-imports'))->toHaveCount(1)
            ->and(Storage::disk('local')->allFiles('company-imports'))->toHaveCount(1);
    });

    it('refuses the third company import of a user too', function () {
        ($this->importCompanies)($this->ann, $this->acme);
        ($this->importContacts)($this->ann, $this->acme);

        ($this->importCompanies)($this->ann, $this->acme)
            ->assertNotified(($this->refused)('Import not queued', IMPORT_USER_REFUSAL));

        Queue::assertPushed(ProcessCompanyImportJob::class, 1);
        expect(Storage::disk('local')->allFiles('company-imports'))->toHaveCount(1);
    });

    it('counts a user across all their organizations', function () {
        $beta = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
        $gamma = User::factory()->onboardingCompleted()->withPersonalOrganization()->create()->personalOrganization();
        $beta->members()->attach($this->ann, ['role' => OrganizationRole::Admin->value]);
        $gamma->members()->attach($this->ann, ['role' => OrganizationRole::Admin->value]);

        ($this->importContacts)($this->ann, $this->acme)->assertNotified('Import queued');
        ($this->importContacts)($this->ann, $beta)->assertNotified('Import queued');

        ($this->importContacts)($this->ann, $gamma)
            ->assertNotified(($this->refused)('Import not queued', IMPORT_USER_REFUSAL));

        Queue::assertPushed(ProcessContactImportJob::class, 2);
    });

    it('refuses the fourth import of an organization', function () {
        ($this->importContacts)($this->ann, $this->acme);
        ($this->importContacts)($this->bob, $this->acme);
        ($this->importContacts)($this->cid, $this->acme);

        ($this->importCompanies)($this->owner, $this->acme)
            ->assertNotified(($this->refused)('Import not queued', IMPORT_ORGANIZATION_REFUSAL));

        Queue::assertPushed(ProcessContactImportJob::class, 3);
        Queue::assertNotPushed(ProcessCompanyImportJob::class);
        expect(Storage::disk('local')->allFiles('company-imports'))->toBe([]);
    });

    it('does not let a full organization block another one', function () {
        ($this->importContacts)($this->ann, $this->acme);
        ($this->importContacts)($this->bob, $this->acme);
        ($this->importContacts)($this->cid, $this->acme);
        ($this->exportContacts)($this->ann, $this->acme);
        ($this->exportContacts)($this->bob, $this->acme);
        ($this->exportContacts)($this->cid, $this->acme);

        ($this->importContacts)($this->gus, $this->globex)->assertNotified('Import queued');

        ($this->in)($this->gus, $this->globex);
        Livewire::test(ListContacts::class)->callAction('exportContacts', ['format' => 'xlsx'])->assertNotified('Export queued');
    });

    it('caps exports per user on every export action', function () {
        ($this->exportContacts)($this->ann, $this->acme)->assertNotified('Export queued');
        ($this->exportContacts)($this->ann, $this->acme)->assertNotified('Export queued');
        Queue::assertPushed(ExportContactsJob::class, 2);

        ($this->exportContacts)($this->ann, $this->acme)
            ->assertNotified(($this->refused)('Export not queued', EXPORT_USER_REFUSAL));

        $companies = Company::factory()->count(2)->create(['organization_id' => $this->acme->id]);
        Livewire::test(ListCompanies::class)
            ->callTableBulkAction('exportSelected', $companies, ['format' => 'csv'])
            ->assertNotified(($this->refused)('Export not queued', EXPORT_USER_REFUSAL));

        Livewire::test(ListContacts::class)
            ->callTableBulkAction('exportSelected', [$this->contact], ['format' => 'csv'])
            ->assertNotified(($this->refused)('Export not queued', EXPORT_USER_REFUSAL));

        Livewire::test(ListDeals::class)
            ->callAction('exportDeals', ['format' => 'xlsx'])
            ->assertNotNotified('Export queued');

        $segment = Segment::factory()->for($this->acme)->published()->create();
        $segment->contacts()->attach([$this->contact->id]);
        Livewire::test(ViewSegment::class, ['record' => $segment->getRouteKey()])
            ->callAction('exportMembers', ['format' => 'xlsx'])
            ->assertNotified(($this->refused)('Export not queued', EXPORT_USER_REFUSAL));

        Queue::assertPushed(ExportContactsJob::class, 2);
        Queue::assertNotPushed(ExportCompaniesJob::class);
        Queue::assertNotPushed(ExportDealsJob::class);
    });

    it('refuses the fourth export of an organization', function () {
        ($this->exportContacts)($this->ann, $this->acme);
        ($this->exportContacts)($this->bob, $this->acme);
        ($this->exportContacts)($this->cid, $this->acme);

        ($this->in)($this->owner, $this->acme);
        Livewire::test(ListContacts::class)
            ->callAction('exportContacts', ['format' => 'xlsx'])
            ->assertNotified(($this->refused)('Export not queued', EXPORT_ORGANIZATION_REFUSAL));

        Queue::assertPushed(ExportContactsJob::class, 3);
    });

    it('keeps imports and exports in separate pools', function () {
        ($this->importContacts)($this->ann, $this->acme);
        ($this->importCompanies)($this->ann, $this->acme);

        ($this->exportContacts)($this->ann, $this->acme)->assertNotified('Export queued');

        ($this->exportContacts)($this->ann, $this->acme)->assertNotified('Export queued');
        ($this->importContacts)($this->ann, $this->acme)
            ->assertNotified(($this->refused)('Import not queued', IMPORT_USER_REFUSAL));
    });

    it('claims nothing when there is nothing to export', function () {
        ($this->exportContacts)($this->ann, $this->acme)->assertNotified('Export queued');

        ($this->in)($this->ann, $this->acme);
        foreach ([1, 2] as $attempt) {
            Livewire::test(ListContacts::class)
                ->set('tableSearch', 'no-contact-has-this-name')
                ->callAction('exportContacts', ['format' => 'xlsx'])
                ->assertNotified('Nothing to export.');
        }

        ($this->exportContacts)($this->ann, $this->acme)->assertNotified('Export queued');
        Queue::assertPushed(ExportContactsJob::class, 2);
    });

    it('does not let a viewer or a member forging the actions use a slot', function (OrganizationRole $role) {
        $forger = ($this->member)($this->acme, $role);
        ($this->in)($forger, $this->acme);

        foreach (range(1, 5) as $attempt) {
            Livewire::test(ListContacts::class)
                ->call('mountAction', 'exportContacts', [], [])
                ->call('callMountedAction');
            Livewire::test(ListContacts::class)
                ->call('mountAction', 'importContacts', [], [])
                ->call('callMountedAction');
            Livewire::test(ListCompanies::class)
                ->call('mountAction', 'importCompanies', [], [])
                ->call('callMountedAction');
        }

        Queue::assertNothingPushed();

        ($this->exportContacts)($this->ann, $this->acme)->assertNotified('Export queued');
        ($this->exportContacts)($this->bob, $this->acme)->assertNotified('Export queued');
        ($this->exportContacts)($this->cid, $this->acme)->assertNotified('Export queued');
        ($this->importContacts)($this->ann, $this->acme)->assertNotified('Import queued');
        ($this->importContacts)($this->bob, $this->acme)->assertNotified('Import queued');
        ($this->importContacts)($this->cid, $this->acme)->assertNotified('Import queued');
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);

    it('sends the import mode and the slot to the job', function () {
        ($this->importContacts)($this->ann, $this->acme);

        Queue::assertPushed(ProcessContactImportJob::class, function (ProcessContactImportJob $job): bool {
            $mode = (fn (): ImportMode => $this->mode)->call($job);

            return $job->importExportSlotKind === ImportExportLimits::IMPORTS
                && is_string($job->importExportSlot)
                && $mode === ImportMode::CreateOnly;
        });
    });

    it('hands an export job its slot', function () {
        ($this->exportContacts)($this->ann, $this->acme);

        Queue::assertPushed(ExportContactsJob::class, fn (ExportContactsJob $job): bool => $job->importExportSlotKind === ImportExportLimits::EXPORTS && is_string($job->importExportSlot));
    });
});

describe('with the real sync queue the jobs end, so their slots are given back', function () {
    it('lets a user import three valid files in a row', function () {
        foreach (range(1, 3) as $attempt) {
            ($this->importContacts)($this->ann, $this->acme, UploadedFile::fake()->createWithContent('contacts.csv', "name,email\nZoe,zoe{$attempt}@x.test\n"))
                ->assertNotified('Import queued')
                ->assertNotNotified('Import not queued');
        }

        expect(Contact::withoutGlobalScopes()->where('email', 'zoe3@x.test')->exists())->toBeTrue();
    });

    it('lets a user export three times in a row', function () {
        foreach (range(1, 3) as $attempt) {
            ($this->exportContacts)($this->ann, $this->acme)
                ->assertNotified('Export queued')
                ->assertNotNotified('Export not queued');
        }

        expect(Storage::disk('local')->allFiles('exports'))->toHaveCount(3);
    });

    it('gives the slot back even when the import file is unreadable', function () {
        foreach (range(1, 3) as $attempt) {
            ($this->importContacts)($this->ann, $this->acme, UploadedFile::fake()->createWithContent('contacts.csv', "name,email\nZoe,zoe@x.test\nAnn\n"))
                ->assertNotNotified('Import not queued');
        }
    });
});

describe('releasing a slot', function () {
    it('gives it back when a contact import fails', function () {
        expect(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-a'))->toBeNull()
            ->and(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-b'))->toBeNull();

        $job = (new ProcessContactImportJob('contact-imports/x.csv', $this->acme->id, $this->ann->id))->holdingSlot(ImportExportLimits::IMPORTS, 'slot-a');
        $job->failed(new RuntimeException('boom'));

        expect(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBeNull()
            ->and(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-d'))->toBe(IMPORT_USER_REFUSAL);
    });

    it('gives it back when a company import fails', function () {
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-b');

        (new ProcessCompanyImportJob('company-imports/x.csv', $this->acme->id, $this->ann->id))
            ->holdingSlot(ImportExportLimits::IMPORTS, 'slot-a')
            ->failed(new RuntimeException('boom'));

        expect(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBeNull()
            ->and(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-d'))->toBe(IMPORT_USER_REFUSAL);
    });

    it('gives it back when an export fails', function () {
        ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-b');

        (new ExportDealsJob([1], $this->acme->id, $this->ann->id, 'xlsx', 'deals.xlsx'))
            ->holdingSlot(ImportExportLimits::EXPORTS, 'slot-a')
            ->failed(new RuntimeException('boom'));

        expect(ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBeNull()
            ->and(ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-d'))->toBe(EXPORT_USER_REFUSAL);
    });

    it('is done by the middleware even when the job throws', function () {
        ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        $job = (new ExportDealsJob([1], $this->acme->id, $this->ann->id, 'xlsx', 'deals.xlsx'))->holdingSlot(ImportExportLimits::EXPORTS, 'slot-a');

        expect(fn () => (new ReleasesImportExportSlot)->handle($job, fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class, 'boom');

        expect(ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-b'))->toBeNull()
            ->and(ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBeNull()
            ->and(ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-d'))->toBe(EXPORT_USER_REFUSAL);
    });

    it('is done by the middleware when the job returns', function () {
        ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-b');
        $job = (new ExportDealsJob([1], $this->acme->id, $this->ann->id, 'xlsx', 'deals.xlsx'))->holdingSlot(ImportExportLimits::EXPORTS, 'slot-a');

        expect((new ReleasesImportExportSlot)->handle($job, fn () => 'done'))->toBe('done')
            ->and(ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBeNull();
    });

    it('keeps the slot of an import that waits for the overlap lock', function () {
        $contacts = (new ProcessContactImportJob('contact-imports/x.csv', $this->acme->id, $this->ann->id))->middleware();
        $companies = (new ProcessCompanyImportJob('company-imports/x.csv', $this->acme->id, $this->ann->id))->middleware();
        $order = [WithoutOverlapping::class, ReleasesImportExportSlot::class, WithTenantContext::class];

        expect(array_map(fn (object $middleware): string => $middleware::class, $contacts))->toBe($order)
            ->and(array_map(fn (object $middleware): string => $middleware::class, $companies))->toBe($order)
            ->and(array_map(fn (object $middleware): string => $middleware::class, (new ExportDealsJob([1], $this->acme->id, $this->ann->id, 'xlsx', 'deals.xlsx'))->middleware()))
            ->toBe([ReleasesImportExportSlot::class, WithTenantContext::class]);
    });

    it('frees one slot only when released twice, and ignores a null or an unknown slot', function () {
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-b');

        ImportExportLimits::release(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::release(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::release(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, null);
        ImportExportLimits::release(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'unknown');

        expect(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBeNull()
            ->and(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-d'))->toBe(IMPORT_USER_REFUSAL);
    });

    it('does nothing for a job that holds no slot', function () {
        ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-b');

        (new ExportDealsJob([1], $this->acme->id, $this->ann->id, 'xlsx', 'deals.xlsx'))->failed(new RuntimeException('boom'));

        expect(ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBe(EXPORT_USER_REFUSAL);
    });

    it('only releases the pool of its own kind, organization and user', function () {
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-b');

        ImportExportLimits::release(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::release(ImportExportLimits::IMPORTS, $this->globex->id, $this->gus->id, 'slot-a');

        expect(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBe(IMPORT_USER_REFUSAL);
    });
});

describe('the slots of a lost job', function () {
    it('expire after four hours', function () {
        $this->travelTo(Carbon::parse('2026-11-12 09:00:00', 'UTC'));
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-b');

        $this->travelTo(Carbon::parse('2026-11-12 12:59:59', 'UTC'));
        expect(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBe(IMPORT_USER_REFUSAL);

        $this->travelTo(Carbon::parse('2026-11-12 13:00:01', 'UTC'));
        expect(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBeNull();
    });

    it('expire one by one, not all together with the first', function () {
        $this->travelTo(Carbon::parse('2026-11-12 09:00:00', 'UTC'));
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-a');

        $this->travelTo(Carbon::parse('2026-11-12 12:00:00', 'UTC'));
        ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-b');

        $this->travelTo(Carbon::parse('2026-11-12 13:30:00', 'UTC'));
        expect(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBeNull()
            ->and(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-d'))->toBe(IMPORT_USER_REFUSAL);
    });
});

describe('claiming', function () {
    it('takes nothing when it refuses', function () {
        $imports = ImportExportLimits::IMPORTS;
        $other = ($this->member)($this->acme, OrganizationRole::Admin);

        expect(ImportExportLimits::claim($imports, $this->acme->id, $this->ann->id, 'slot-a'))->toBeNull()
            ->and(ImportExportLimits::claim($imports, $this->acme->id, $this->ann->id, 'slot-b'))->toBeNull()
            ->and(ImportExportLimits::claim($imports, $this->acme->id, $this->bob->id, 'slot-c'))->toBeNull()
            ->and(ImportExportLimits::claim($imports, $this->acme->id, $this->ann->id, 'slot-d'))->toBe(IMPORT_ORGANIZATION_REFUSAL)
            ->and(ImportExportLimits::claim($imports, $this->acme->id, $other->id, 'slot-e'))->toBe(IMPORT_ORGANIZATION_REFUSAL);

        ImportExportLimits::release($imports, $this->acme->id, $this->bob->id, 'slot-c');

        expect(ImportExportLimits::claim($imports, $this->acme->id, $this->ann->id, 'slot-f'))->toBe(IMPORT_USER_REFUSAL)
            ->and(ImportExportLimits::claim($imports, $this->acme->id, $other->id, 'slot-g'))->toBeNull()
            ->and(ImportExportLimits::claim($imports, $this->acme->id, $this->bob->id, 'slot-h'))->toBe(IMPORT_ORGANIZATION_REFUSAL);

        ImportExportLimits::release($imports, $this->acme->id, $this->ann->id, 'slot-a');

        expect(ImportExportLimits::claim($imports, $this->acme->id, $this->bob->id, 'slot-i'))->toBeNull();
    });

    it('survives a damaged cache entry', function () {
        Cache::put('import-export-slots:imports:user:'.$this->ann->id, 'garbage', 60);
        Cache::put('import-export-slots:imports:organization:'.$this->acme->id, ['slot-a' => 'never', 'slot-b' => null], 60);

        expect(ImportExportLimits::claim(ImportExportLimits::IMPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBeNull();
    });

    it('answers a plain message and queues nothing when another request holds the lock', function () {
        Queue::fake();
        Sleep::fake(syncWithCarbon: true);
        $lock = Cache::lock('import-export-slots:lock', 10);
        expect($lock->get())->toBeTrue();

        ($this->exportContacts)($this->ann, $this->acme)
            ->assertNotified(($this->refused)('Export not queued', 'Too many files are being queued right now. Try again in a moment.'));

        ($this->importContacts)($this->ann, $this->acme)
            ->assertNotified(($this->refused)('Import not queued', 'Too many files are being queued right now. Try again in a moment.'));

        Queue::assertNothingPushed();
        expect(Storage::disk('local')->allFiles('contact-imports'))->toBe([]);

        $lock->release();

        ($this->exportContacts)($this->ann, $this->acme)->assertNotified('Export queued');
    });

    it('keeps the slot when the lock is busy at release time', function () {
        ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-b');
        Sleep::fake(syncWithCarbon: true);
        $lock = Cache::lock('import-export-slots:lock', 10);
        $lock->get();

        ImportExportLimits::release(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-a');
        $lock->release();

        expect(ImportExportLimits::claim(ImportExportLimits::EXPORTS, $this->acme->id, $this->ann->id, 'slot-c'))->toBe(EXPORT_USER_REFUSAL);
    });
});
