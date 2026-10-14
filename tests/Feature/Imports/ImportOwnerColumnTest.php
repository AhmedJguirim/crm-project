<?php

use App\Enums\ContactStatus;
use App\Enums\ExportFormat;
use App\Enums\ImportMode;
use App\Enums\OrganizationRole;
use App\Jobs\ProcessCompanyImportJob;
use App\Jobs\ProcessContactImportJob;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\CompanyExportWriter;
use App\Services\CompanyImportService;
use App\Services\ContactExportWriter;
use App\Services\ContactImportService;
use App\Services\Owners\ImportOwnerResolver;
use Filament\Facades\Filament;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->test = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Test User', 'email' => 'test@example.com']);
    $this->org = $this->test->personalOrganization();
    $this->actingAs($this->test);
    Filament::setTenant($this->org);

    $this->bob = User::factory()->create(['name' => 'Bob Member', 'email' => 'bob@example.com']);
    $this->sales = User::factory()->create(['name' => 'Sales Desk', 'email' => '+sales@example.com']);
    $this->carol = User::factory()->create(['name' => 'Carol Former', 'email' => 'carol@example.com']);
    $this->org->members()->attach($this->bob, ['role' => OrganizationRole::Member->value]);
    $this->org->members()->attach($this->sales, ['role' => OrganizationRole::Viewer->value]);
    $this->org->members()->attach($this->carol, ['role' => OrganizationRole::Member->value]);
    $this->org->members()->detach($this->carol);

    $this->eve = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Eve Elsewhere', 'email' => 'eve@example.com']);

    $this->contactService = fn (ImportMode $mode = ImportMode::CreateOnly, ?int $importerId = -1): ContactImportService => new ContactImportService($this->org->id, null, $mode, $importerId === -1 ? $this->test->id : $importerId);
    $this->companyService = fn (ImportMode $mode = ImportMode::CreateOnly, ?int $importerId = -1): CompanyImportService => new CompanyImportService($this->org->id, null, $mode, $importerId === -1 ? $this->test->id : $importerId);
    $this->contactNamed = fn (string $email): ?Contact => Contact::forOrganization($this->org->id)->where('email', $email)->first();
    $this->companyNamed = fn (string $name): ?Company => Company::forOrganization($this->org->id)->where('name', $name)->first();
    $this->ownerEmailOf = fn (Contact|Company|null $record): ?string => $record?->owner_id === null ? null : User::query()->whereKey($record->owner_id)->value('email');
    $this->importContact = fn (array $row, ?ContactImportService $service = null): array => ($service ?? ($this->contactService)())->processRow($row, []);
    $this->importCompany = fn (array $row, ?CompanyImportService $service = null): array => ($service ?? ($this->companyService)())->processRow($row);
    $this->noMember = fn (string $email): array => ['success' => false, 'error' => "No member of this organization has the email '{$email}'."];
});

describe('contacts', function () {
    it('gives a created contact the owner the cell names', function (?string $cell, array $expected) {
        $row = ['name' => 'Ann Lee', 'email' => 'ann@example.com', 'company' => 'Nova'];

        if ($cell !== null) {
            $row['owner'] = $cell;
        }

        $result = ($this->importContact)($row);

        expect($result)->toBe($expected['result'] ?? ['success' => true, 'error' => null])
            ->and(($this->ownerEmailOf)(($this->contactNamed)('ann@example.com')))->toBe($expected['owner']);

        if (! $result['success']) {
            expect(Contact::forOrganization($this->org->id)->count())->toBe(0)
                ->and(Company::forOrganization($this->org->id)->count())->toBe(0);
        }
    })->with([
        'without an owner column' => [null, ['owner' => 'test@example.com']],
        'a member in other case and with spaces' => [' BOB@Example.com ', ['owner' => 'bob@example.com']],
        'blank' => ['', ['owner' => null]],
        'a dash' => ['-', ['owner' => null]],
        'an exported formula guard' => ["'+sales@example.com", ['owner' => '+sales@example.com']],
        'a former member' => ['carol@example.com', ['owner' => null, 'result' => ['success' => false, 'error' => "No member of this organization has the email 'carol@example.com'."]]],
        'a member of another organization only' => ['eve@example.com', ['owner' => null, 'result' => ['success' => false, 'error' => "No member of this organization has the email 'eve@example.com'."]]],
        'a formula' => ['=HYPERLINK("http://x")', ['owner' => null, 'result' => ['success' => false, 'error' => 'No member of this organization has the email \'=hyperlink("http://x")\'.']]],
    ]);

    it('refuses an owner cell over 255 characters and accepts one of exactly 255', function () {
        $tooLong = str_repeat('a', 244).'@example.com';
        $exact = str_repeat('a', 243).'@example.com';

        expect(($this->importContact)(['name' => 'Ann', 'email' => 'ann@example.com', 'owner' => $tooLong]))->toBe(['success' => false, 'error' => "Invalid value for field 'owner': ".Str::limit($tooLong, 50)])
            ->and(mb_strlen($tooLong))->toBe(256)
            ->and(($this->importContact)(['name' => 'Ann', 'email' => 'ann@example.com', 'owner' => $exact]))->toBe(($this->noMember)($exact))
            ->and(($this->importContact)(['name' => 'Ben', 'email' => 'ben@example.com']))->toBe(['success' => true, 'error' => null]);
    });

    it('gives no owner without an importer or when the importer left the organization', function () {
        $dave = User::factory()->create(['email' => 'dave@example.com']);
        $this->org->members()->attach($dave, ['role' => OrganizationRole::Member->value]);
        $this->org->members()->detach($dave);

        ($this->importContact)(['name' => 'Ann Lee', 'email' => 'ann@example.com'], ($this->contactService)(ImportMode::CreateOnly, null));
        ($this->importContact)(['name' => 'Ben Lee', 'email' => 'ben@example.com'], ($this->contactService)(ImportMode::CreateOnly, $dave->id));

        expect(($this->contactNamed)('ann@example.com')->owner_id)->toBeNull()
            ->and(($this->contactNamed)('ben@example.com')->owner_id)->toBeNull();
    });

    it('changes the owner of an existing contact only when the cell says so', function (string $before, ?string $cell, string $failure, ?string $expected) {
        $owner = ['bob' => $this->bob, 'carol' => $this->carol][$before];
        Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Old', 'email' => 'ann@example.com', 'owner_id' => $owner->id]);
        $row = ['email' => 'ann@example.com'];

        if ($cell !== null) {
            $row['owner'] = $cell;
        }

        $result = ($this->importContact)($row, ($this->contactService)(ImportMode::UpdateOnly));

        expect($result['error'])->toBe($failure === '' ? null : $failure)
            ->and(($this->ownerEmailOf)(($this->contactNamed)('ann@example.com')))->toBe($expected);
    })->with([
        'no column' => ['bob', null, '', 'bob@example.com'],
        'blank' => ['bob', '', '', 'bob@example.com'],
        'a dash clears it' => ['bob', '-', '', null],
        'another member' => ['bob', 'test@example.com', '', 'test@example.com'],
        'the former member who owns it' => ['carol', 'CAROL@example.com', '', 'carol@example.com'],
        'a former member who does not own it' => ['bob', 'carol@example.com', "No member of this organization has the email 'carol@example.com'.", 'bob@example.com'],
    ]);

    it('gives a company created by a contact row the owner of the contact', function () {
        ($this->importContact)(['name' => 'Ann Lee', 'email' => 'ann@example.com', 'company' => 'Nova', 'owner' => 'bob@example.com']);

        expect(($this->ownerEmailOf)(($this->companyNamed)('Nova')))->toBe('bob@example.com');

        Contact::factory()->create(['organization_id' => $this->org->id, 'email' => 'zed@example.com', 'owner_id' => $this->bob->id]);
        ($this->importContact)(['email' => 'zed@example.com', 'company' => 'Zeta'], ($this->contactService)(ImportMode::UpdateOnly));
        ($this->importContact)(['email' => 'zed@example.com', 'company' => 'Omega', 'owner' => '-'], ($this->contactService)(ImportMode::UpdateOnly));

        expect(($this->ownerEmailOf)(($this->companyNamed)('Zeta')))->toBe('bob@example.com')
            ->and(($this->companyNamed)('Omega')->owner_id)->toBeNull();
    });

    it('reads the members once and gives what a fresh resolver gives', function () {
        $rows = [['owner' => 'bob@example.com'], ['owner' => 'eve@example.com'], ['owner' => 'Bob@Example.com'], []];
        $service = ($this->contactService)();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $results = array_map(fn (array $row): array => ($this->importContact)(['name' => 'N', 'email' => 'u'.count($queries).uniqid().'@example.com', ...$row], $service), $rows);
        $memberQueries = array_filter($queries, fn (string $sql): bool => str_contains($sql, '"organization_user"'));
        $fresh = array_map(fn (array $row): array => (new ImportOwnerResolver($this->org->id, $this->test->id))->forNewRecord($row), $rows);
        $resolver = new ImportOwnerResolver($this->org->id, $this->test->id);
        $sequence = [];

        foreach ($rows as $row) {
            $sequence[] = $resolver->forNewRecord($row);
        }

        expect(array_column($results, 'success'))->toBe([true, false, true, true])
            ->and($sequence)->toBe($fresh)
            ->and($memberQueries)->toHaveCount(1);
    });

    it('reads the owner from a European csv and strips a NUL byte of the cell', function () {
        Storage::fake('local');
        Storage::disk('local')->put('contact-imports/owner-nul.csv', "name;email;owner\nAnn Lee;ann@example.com;bob\0@example.com\nBen Lee;ben@example.com;=cmd|x\n");
        DatabaseNotification::query()->delete();

        ProcessContactImportJob::dispatchSync('contact-imports/owner-nul.csv', $this->org->id, $this->test->id);

        expect(($this->ownerEmailOf)(($this->contactNamed)('ann@example.com')))->toBe('bob@example.com')
            ->and(($this->contactNamed)('ben@example.com'))->toBeNull()
            ->and(DatabaseNotification::where('notifiable_id', $this->test->id)->sole()->data['body'])->toContain('Imported: 1 | Failed: 1');
    });

    it('gives the contacts of a file without an owner column to the person who ran the import', function () {
        Storage::fake('local');
        Storage::disk('local')->put('contact-imports/no-owner.csv', "name,email\nAnn Lee,ann@example.com\n");

        ProcessContactImportJob::dispatchSync('contact-imports/no-owner.csv', $this->org->id, $this->bob->id);
        $this->org->members()->detach($this->bob);
        Storage::disk('local')->put('contact-imports/no-owner-left.csv', "name,email\nBen Lee,ben@example.com\n");
        ProcessContactImportJob::dispatchSync('contact-imports/no-owner-left.csv', $this->org->id, $this->bob->id);

        expect(($this->ownerEmailOf)(($this->contactNamed)('ann@example.com')))->toBe('bob@example.com')
            ->and(($this->contactNamed)('ben@example.com')->owner_id)->toBeNull();
    });

    it('keeps an unowned or former member owner through the update only round trip', function () {
        $contact = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Zoe', 'email' => 'zoe@example.com', 'owner_id' => $this->carol->id, 'status' => ContactStatus::Lead]);
        $contact->load(['tags', 'companies', 'owner']);
        $writer = new ContactExportWriter(collect());
        $ownerIndex = array_search('owner', $writer->headers(), true);
        $row = $writer->row($contact, ExportFormat::Csv);
        $service = ($this->contactService)(ImportMode::UpdateOnly);

        $result = $service->processRow(array_combine($writer->headers(), $row), []);

        expect($row[$ownerIndex])->toBe('carol@example.com')
            ->and($result)->toBe(['success' => true, 'error' => null])
            ->and(($this->contactNamed)('zoe@example.com')->owner_id)->toBe($this->carol->id);
    });
});

describe('companies', function () {
    it('gives a created company the owner the cell names', function (?string $cell, ?string $expectedOwner, ?string $unknown) {
        $row = ['name' => 'Nova', 'website' => 'nova.io'];

        if ($cell !== null) {
            $row['owner'] = $cell;
        }

        $result = ($this->importCompany)($row);

        expect($result)->toBe($unknown === null ? ['success' => true, 'error' => null] : ($this->noMember)($unknown))
            ->and(($this->ownerEmailOf)(($this->companyNamed)('Nova')))->toBe($expectedOwner);
    })->with([
        'without an owner column' => [null, 'test@example.com', null],
        'a member' => ['bob@example.com', 'bob@example.com', null],
        'blank' => ['', null, null],
        'a dash' => ['-', null, null],
        'a member of another organization only' => ['eve@example.com', null, 'eve@example.com'],
    ]);

    it('changes the owner of an existing company only when the cell says so', function (array $cells, ?string $expectedOwner, ?string $expectedStreet) {
        $nova = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Nova', 'website' => 'nova.io', 'owner_id' => $this->bob->id]);

        $result = ($this->importCompany)(['name' => 'Nova', 'website' => 'nova.io', ...$cells], ($this->companyService)(ImportMode::UpdateOnly));

        expect($result)->toBe(['success' => true, 'error' => null])
            ->and(($this->ownerEmailOf)($nova->fresh()))->toBe($expectedOwner)
            ->and($nova->fresh()->load('address')->address?->street)->toBe($expectedStreet);
    })->with([
        'a dash clears it' => [['owner' => '-'], null, null],
        'blank keeps it' => [['owner' => ''], 'bob@example.com', null],
        'a member and a street, the owner never lands in the address' => [['street' => '1 Main St', 'owner' => 'test@example.com'], 'test@example.com', '1 Main St'],
    ]);

    it('refuses a non member on an existing company and leaves it as it was', function () {
        $nova = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Nova', 'website' => 'nova.io', 'owner_id' => $this->bob->id]);

        $result = ($this->importCompany)(['name' => 'Nova', 'website' => 'nova.io', 'owner' => 'eve@example.com'], ($this->companyService)(ImportMode::UpdateOnly));

        expect($result)->toBe(($this->noMember)('eve@example.com'))
            ->and($nova->fresh()->owner_id)->toBe($this->bob->id);
    });

    it('gives the companies of a file without an owner column to the person who ran the import', function () {
        Storage::fake('local');
        Storage::disk('local')->put('company-imports/no-owner.csv', "name,website\nNova,nova.io\n");

        ProcessCompanyImportJob::dispatchSync('company-imports/no-owner.csv', $this->org->id, $this->bob->id);

        expect(($this->ownerEmailOf)(($this->companyNamed)('Nova')))->toBe('bob@example.com');
    });

    it('keeps the owner out of the detail columns', function () {
        expect(CompanyImportService::COLUMNS)->toBe(['name', 'website', 'type', 'phone', 'industry', 'employees', 'annual revenue', 'street', 'city', 'zip', 'country', 'notes', 'owner']);
    });
});

describe('exports', function () {
    it('writes the owner email of a contact in a csv with the formula guard, and as is in an xlsx', function () {
        $contacts = collect([
            ['Ann', $this->bob->id],
            ['Ray', null],
            ['Zoe', $this->carol->id],
            ['Sam', $this->sales->id],
        ])->map(fn (array $data): Contact => Contact::factory()->create(['organization_id' => $this->org->id, 'name' => $data[0], 'owner_id' => $data[1]])->load(['tags', 'companies', 'owner']));
        $writer = new ContactExportWriter(collect());
        $index = array_search('owner', $writer->headers(), true);

        $csv = $contacts->map(fn (Contact $contact): string => $writer->row($contact, ExportFormat::Csv)[$index])->all();
        $xlsx = $contacts->map(fn (Contact $contact): string => $writer->row($contact, ExportFormat::Xlsx)[$index])->all();

        expect($csv)->toBe(['bob@example.com', '', 'carol@example.com', "'+sales@example.com"])
            ->and($xlsx)->toBe(['bob@example.com', '', 'carol@example.com', '+sales@example.com'])
            ->and($index)->toBe(count(ContactExportWriter::COLUMNS) - 1);
    });

    it('writes the owner of a company before the contacts count', function () {
        $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Nova', 'owner_id' => $this->bob->id]);
        $company = Company::query()->withCount('contacts')->with(['address', 'companyTypeWithTrashed', 'owner'])->findOrFail($company->id);
        $writer = new CompanyExportWriter(collect());

        expect($writer->headers())->toBe(['id', 'name', 'website', 'type', 'phone', 'industry', 'employees', 'annual revenue', 'street', 'city', 'zip', 'country', 'notes', 'owner', 'contacts'])
            ->and($writer->row($company, ExportFormat::Csv)[13])->toBe('bob@example.com');
    });
});
