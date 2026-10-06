<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\SyncSegmentMembership;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Segment;
use App\Models\User;
use App\Services\ContactImportService;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);

    $this->import = function (string $csv): string {
        $path = 'contact-imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);

        DatabaseNotification::query()->delete();
        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'];
    };
    $this->contact = fn (string $email): Contact => Contact::forOrganization($this->org->id)->where('email', $email)->firstOrFail();
    $this->companiesOf = fn (string $email): array => ($this->contact)($email)->companies()->withoutGlobalScope('organization')->orderBy('companies.id')->pluck('name')->all();
    $this->companies = fn (): Collection => Company::forOrganization($this->org->id)->withTrashed()->orderBy('id')->get();
    $this->makeCompany = fn (string $name, ?string $website = null): Company => Company::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        'website' => $website,
    ]);
});

describe('the company columns', function () {
    it('creates one company for two rows naming it and links both contacts', function () {
        $body = ($this->import)("name,email,company\nAnn,ann@x.test,Acme\nBob,bob@x.test,acme\n");

        expect(($this->companies)()->pluck('name')->all())->toBe(['Acme'])
            ->and(($this->companiesOf)('ann@x.test'))->toBe(['Acme'])
            ->and(($this->companiesOf)('bob@x.test'))->toBe(['Acme'])
            ->and($body)->toBe("Imported: 2 | Failed: 0\n\nCompanies created: 1");
    });

    it('links an existing company found by name and creates nothing', function () {
        $existing = ($this->makeCompany)('ACME ');

        $body = ($this->import)("name,email,company\nAnn,ann@x.test,Acme\n");

        expect(($this->contact)('ann@x.test')->companies()->withoutGlobalScope('organization')->pluck('companies.id')->all())->toBe([$existing->id])
            ->and(($this->companies)())->toHaveCount(1)
            ->and($body)->toBe('Imported: 1 | Failed: 0')
            ->and($body)->not->toContain('Companies created');
    });

    it('links an existing company found by website', function () {
        ($this->makeCompany)('Acme Corporation', 'https://www.acme.com');

        $body = ($this->import)("name,email,company,company website\nAnn,ann@x.test,Acme,acme.com\n");

        expect(($this->companiesOf)('ann@x.test'))->toBe(['Acme Corporation'])
            ->and(($this->companies)())->toHaveCount(1)
            ->and($body)->not->toContain('Companies created');
    });

    it('creates a new company when the name is taken by a company with another website', function () {
        ($this->makeCompany)('Acme', 'acme.com');

        ($this->import)("name,email,company,company website\nAnn,ann@x.test,Acme,acme.co.uk\n");

        $companies = ($this->companies)();

        expect($companies)->toHaveCount(2)
            ->and($companies->last()->only(['name', 'website', 'domain']))->toBe(['name' => 'Acme', 'website' => 'acme.co.uk', 'domain' => 'acme.co.uk'])
            ->and(($this->contact)('ann@x.test')->companies()->withoutGlobalScope('organization')->pluck('companies.id')->all())->toBe([$companies->last()->id]);
    });

    it('names a company after its website when the name is blank', function () {
        ($this->import)("name,email,company,company website\nAnn,ann@x.test,,https://nova.io\n");

        expect(($this->companies)()->map->only(['name', 'website', 'domain'])->all())->toBe([['name' => 'nova.io', 'website' => 'https://nova.io', 'domain' => 'nova.io']])
            ->and(($this->companiesOf)('ann@x.test'))->toBe(['nova.io']);
    });

    it('names a company after the domain of a website with a path and www', function () {
        ($this->import)("name,email,company website\nAnn,ann@x.test,\" https://www.Nova.io/about \"\n");

        expect(($this->companies)()->map->only(['name', 'website'])->all())->toBe([['name' => 'nova.io', 'website' => 'https://www.Nova.io/about']]);
    });

    it('fails the row when the company is ambiguous', function () {
        ($this->makeCompany)('Acme');
        ($this->makeCompany)('Acme');

        $body = ($this->import)("name,email,company\nAnn,ann@x.test,Acme\n");

        expect($body)->toContain('Imported: 0 | Failed: 1')
            ->and($body)->toContain('Row 1: Several companies are named Acme. Add the company website to choose.')
            ->and(Contact::forOrganization($this->org->id)->count())->toBe(0);
    });

    it('fails the third row when the second one created a company with the same name and another website', function () {
        ($this->makeCompany)('Acme', 'acme.com');

        $body = ($this->import)("name,email,company,company website\nAnn,ann@x.test,Acme,\nBob,bob@x.test,Acme,acme.co.uk\nCara,cara@x.test,Acme,\n");

        expect($body)->toContain('Imported: 2 | Failed: 1')
            ->and($body)->toContain('Row 3: Several companies are named Acme. Add the company website to choose.')
            ->and(($this->companiesOf)('ann@x.test'))->toBe(['Acme'])
            ->and(($this->companiesOf)('bob@x.test'))->toBe(['Acme'])
            ->and(($this->companies)())->toHaveCount(2)
            ->and(Contact::forOrganization($this->org->id)->where('email', 'cara@x.test')->exists())->toBeFalse();
    });

    it('fails a company name longer than 255 characters and imports the next row', function () {
        $long = str_repeat('a', 256);

        $body = ($this->import)("name,email,company\nAnn,ann@x.test,{$long}\nBob,bob@x.test,Nova\n");

        expect($body)->toContain('Imported: 1 | Failed: 1')
            ->and($body)->toContain("Invalid value for field 'company': {$long}")
            ->and(($this->companies)()->pluck('name')->all())->toBe(['Nova'])
            ->and(Contact::forOrganization($this->org->id)->where('email', 'ann@x.test')->exists())->toBeFalse()
            ->and(($this->companiesOf)('bob@x.test'))->toBe(['Nova']);
    });

    it('fails a company website longer than 255 characters and imports the next row', function () {
        $long = 'acme.com/'.str_repeat('a', 300);

        $body = ($this->import)("name,email,company,company website\nAnn,ann@x.test,Foo,{$long}\nBob,bob@x.test,Nova,nova.io\n");

        expect($body)->toContain('Imported: 1 | Failed: 1')
            ->and($body)->toContain("Invalid value for field 'company website': {$long}")
            ->and(($this->companies)()->pluck('name')->all())->toBe(['Nova'])
            ->and(Contact::forOrganization($this->org->id)->where('email', 'ann@x.test')->exists())->toBeFalse()
            ->and(($this->companiesOf)('bob@x.test'))->toBe(['Nova']);
    });

    it('accepts a company name of exactly 255 characters', function () {
        $name = str_repeat('a', 255);

        $body = ($this->import)("name,email,company\nAnn,ann@x.test,{$name}\n");

        expect($body)->toContain('Imported: 1 | Failed: 0')
            ->and(($this->companiesOf)('ann@x.test'))->toBe([$name]);
    });

    it('fails the row when the company is in the trash and does not restore it', function () {
        ($this->makeCompany)('Old Co', 'old.test')->delete();

        $body = ($this->import)("name,email,company,company website\nAnn,ann@x.test,Old Co,old.test\n");

        expect($body)->toContain('Row 1: The company Old Co is in the trash. Restore it first.')
            ->and(($this->companies)())->toHaveCount(1)
            ->and(($this->companies)()->first()->trashed())->toBeTrue()
            ->and(Contact::forOrganization($this->org->id)->count())->toBe(0);
    });

    it('fails the row when the company website is not valid and creates nothing', function () {
        $body = ($this->import)("name,email,company,company website\nAnn,ann@x.test,Nova,not a website\n");

        expect($body)->toContain("Row 1: Invalid value for field 'company website': not a website")
            ->and(($this->companies)())->toHaveCount(0)
            ->and(Contact::forOrganization($this->org->id)->count())->toBe(0);
    });

    it('creates no company for a row that fails on its own data', function () {
        $body = ($this->import)("name,email,company\nAnn,not-an-email,Nova\n");

        expect($body)->toContain('Invalid email: not-an-email')
            ->and(($this->companies)())->toHaveCount(0)
            ->and($body)->not->toContain('Companies created');
    });

    it('creates no company for a contact that already exists', function () {
        Contact::factory()->create(['organization_id' => $this->org->id, 'email' => 'ann@x.test']);

        $body = ($this->import)("name,email,company\nAnn,ann@x.test,Nova\n");

        expect($body)->toContain("A contact with email 'ann@x.test' already exists.")
            ->and(($this->companies)())->toHaveCount(0);
    });

    it('creates no company when the unique custom value fails the row', function () {
        $field = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Badge', 'type' => 'text', 'unique' => true, 'order' => 1]);
        Contact::factory()->create(['organization_id' => $this->org->id, 'custom_field_values' => [$field->key => 'B1']]);

        $body = ($this->import)("name,email,company,Badge\nAnn,ann@x.test,Nova,B1\n");

        expect($body)->toContain("Duplicate value for unique field 'Badge'.")
            ->and(($this->companies)())->toHaveCount(0);
    });

    it('rolls the new company back when the contact is created by someone else after the check', function () {
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if (! $raced && str_contains($query->sql, 'from "contacts"') && in_array('ann@x.test', $query->bindings, true)) {
                $raced = true;
                Contact::factory()->createQuietly(['organization_id' => $this->org->id, 'name' => 'Created elsewhere', 'email' => 'ann@x.test']);
            }
        });

        $body = ($this->import)("name,email,company\nAnn,ann@x.test,Nova\nBob,bob@x.test,Nova\n");

        expect($raced)->toBeTrue()
            ->and($body)->toContain('Imported: 1 | Failed: 1')
            ->and($body)->toContain("Row 1: A contact with email 'ann@x.test' already exists.")
            ->and($body)->toContain('Companies created: 1')
            ->and(($this->companies)())->toHaveCount(1)
            ->and(($this->contact)('ann@x.test')->name)->toBe('Created elsewhere')
            ->and(($this->companiesOf)('ann@x.test'))->toBe([])
            ->and(($this->companiesOf)('bob@x.test'))->toBe(['Nova']);
    });

    it('does not count or remember a company whose row was rolled back', function () {
        $raced = false;
        DB::listen(function (QueryExecuted $query) use (&$raced): void {
            if (! $raced && str_contains($query->sql, 'from "contacts"') && in_array('ann@x.test', $query->bindings, true)) {
                $raced = true;
                Contact::factory()->createQuietly(['organization_id' => $this->org->id, 'name' => 'Created elsewhere', 'email' => 'ann@x.test']);
            }
        });

        $service = new ContactImportService($this->org->id);
        $first = $service->processRow(['name' => 'Ann', 'email' => 'ann@x.test', 'company' => 'Nova', 'company website' => 'nova.io'], []);

        expect($first['success'])->toBeFalse()
            ->and($service->createdCompaniesCount())->toBe(0)
            ->and(($this->companies)())->toHaveCount(0);

        $second = $service->processRow(['name' => 'Bob', 'email' => 'bob@x.test', 'company' => 'Nova', 'company website' => 'nova.io'], []);

        expect($second['success'])->toBeTrue()
            ->and($service->createdCompaniesCount())->toBe(1)
            ->and(($this->companies)())->toHaveCount(1)
            ->and(($this->companiesOf)('bob@x.test'))->toBe(['Nova']);
    });

    it('leaves the contact without a company when there are no company columns', function () {
        $body = ($this->import)("name,email\nBob,bob@x.test\n");

        expect(($this->companiesOf)('bob@x.test'))->toBe([])
            ->and(($this->companies)())->toHaveCount(0)
            ->and($body)->toBe('Imported: 1 | Failed: 0');
    });

    it('leaves the contact without a company when both cells are blank', function () {
        ($this->import)("name,email,company,company website\nBob,bob@x.test, ,\n");

        expect(($this->companiesOf)('bob@x.test'))->toBe([])
            ->and(($this->companies)())->toHaveCount(0);
    });

    it('keeps semicolons in the company name', function () {
        ($this->import)("name,email,company\nAnn,ann@x.test,\"Smith; Jones & Co\"\n");

        expect(($this->companies)()->pluck('name')->all())->toBe(['Smith; Jones & Co'])
            ->and(($this->companiesOf)('ann@x.test'))->toBe(['Smith; Jones & Co']);
    });

    it('matches the headers of the company columns loosely', function () {
        ($this->import)("name,email,Company, Company Website \nAnn,ann@x.test,Nova,nova.io\n");

        expect(($this->companies)()->map->only(['name', 'domain'])->all())->toBe([['name' => 'Nova', 'domain' => 'nova.io']]);
    });

    it('does not report the company columns as ignored', function () {
        $body = ($this->import)("name,email,company,company website\nAnn,ann@x.test,Nova,nova.io\n");

        expect($body)->not->toContain('Ignored columns');
    });

    it('finds the company created by an earlier row through its website', function () {
        ($this->import)("name,email,company,company website\nAnn,ann@x.test,Acme,acme.com\nBob,bob@x.test,Acme Inc,https://www.acme.com\n");

        expect(($this->companies)())->toHaveCount(1)
            ->and(($this->companiesOf)('bob@x.test'))->toBe(['Acme']);
    });

    it('remembers a company it created, so the next row does not search for it again', function () {
        $service = new ContactImportService($this->org->id);
        $service->processRow(['name' => 'Ann', 'email' => 'ann@x.test', 'company' => 'Nova', 'company website' => 'nova.io'], []);

        DB::enableQueryLog();
        $second = $service->processRow(['name' => 'Bob', 'email' => 'bob@x.test', 'company' => 'Nova', 'company website' => 'nova.io'], []);
        $searches = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "companies"') && str_contains($query['query'], '"domain" = ?'));
        DB::disableQueryLog();

        expect($second['success'])->toBeTrue()
            ->and($searches)->toBeEmpty()
            ->and($service->createdCompaniesCount())->toBe(1);
    });

    it('puts the companies line right after the imported line, before the other lines', function () {
        $body = ($this->import)("name,email,company,Extra\nAnn,ann@x.test,Nova,x\nBob,not-an-email,Nova,x\n");

        expect($body)->toStartWith("Imported: 1 | Failed: 1\n\nCompanies created: 1\n\nIgnored columns (no matching field): \"Extra\"\n\nRow 2: Invalid email: not-an-email");
    });
});

describe('segments', function () {
    it('see the contacts linked to a company by the import', function () {
        $acme = ($this->makeCompany)('Acme');
        $segment = Segment::factory()->for($this->org)->published()->withRules([
            new SegmentRuleData('rule-1', 'Acme people', [
                SegmentConditionData::make(SegmentConditionType::Company, null, SegmentOperator::BelongsToAnyOf, ['values' => [$acme->id]]),
            ]),
        ])->create();

        ($this->import)("name,email,company\nAnn,ann@x.test,Acme\nBob,bob@x.test,Acme\nCid,cid@x.test,Other\n");

        expect($segment->contacts()->pluck('email')->sort()->values()->all())->toBe(['ann@x.test', 'bob@x.test']);
    });

    it('queues nothing when a company is created', function () {
        Queue::fake([SyncSegmentMembership::class]);

        Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Nova']);

        Queue::assertNothingPushed();
    });
});
