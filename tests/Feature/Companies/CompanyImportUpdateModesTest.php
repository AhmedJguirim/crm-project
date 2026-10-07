<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\CompanyIndustry;
use App\Enums\ImportMode;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentOperator;
use App\Jobs\ProcessCompanyImportJob;
use App\Jobs\SyncSegmentMembership;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();

    $this->sme = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'SME']);
    $this->vat = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'VAT Number', 'type' => 'text', 'unique' => true, 'order' => 1]);

    $this->initech = Company::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Initech',
        'website' => 'initech.com',
        'company_type_id' => $this->sme->id,
        'industry' => CompanyIndustry::Software,
        'employees' => 85,
        'notes' => 'big',
        'address_id' => Address::factory()->create(['organization_id' => $this->org->id, 'street' => '1 Main St', 'city' => 'Paris'])->id,
        'custom_field_values' => [$this->vat->key => 'FR1'],
    ]);
    $this->plain = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Plain Co', 'website' => null, 'company_type_id' => null, 'industry' => null, 'employees' => null, 'notes' => null, 'address_id' => null, 'phone' => null, 'custom_field_values' => []]);
    Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Other Co', 'website' => 'other.com', 'custom_field_values' => [$this->vat->key => 'FR2']]);

    $this->importFile = function (string $content, ImportMode $mode): DatabaseNotification {
        $path = 'company-imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $content);
        DatabaseNotification::query()->delete();

        ProcessCompanyImportJob::dispatchSync($path, $this->org->id, $this->user->id, $mode);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->latest('created_at')->firstOrFail();
    };

    $this->update = fn (string $content): DatabaseNotification => ($this->importFile)($content, ImportMode::UpdateOnly);
    $this->upsert = fn (string $content): DatabaseNotification => ($this->importFile)($content, ImportMode::CreateAndUpdate);
    $this->fresh = fn (Company $company): Company => Company::withTrashed()->withoutGlobalScope('organization')->findOrFail($company->id);
    $this->count = fn (): int => Company::withTrashed()->withoutGlobalScope('organization')->where('organization_id', $this->org->id)->count();
    $this->addressOf = fn (Company $company): ?Address => Address::withoutGlobalScopes()->find(($this->fresh)($company)->address_id);
});

it('keeps create only as it was', function () {
    $notification = ($this->importFile)("name,website\nInitech,initech.com\n", ImportMode::CreateOnly);

    expect($notification->data['body'])->toStartWith('Imported: 0 | Failed: 1')
        ->and($notification->data['body'])->toContain('A company named Initech (initech.com) already exists.');
});

it('never reads the id column in create only', function () {
    $notification = ($this->importFile)("id,name\n{$this->initech->id},Brand New\n", ImportMode::CreateOnly);

    expect($notification->data['body'])->toBe('Imported: 1 | Failed: 0')
        ->and(($this->fresh)($this->initech)->name)->toBe('Initech');
});

it('updates by name and website, a blank cell leaving the value', function () {
    $notification = ($this->update)("name,website,industry,employees,notes\nInitech,initech.com,Finance & Banking,,\n");
    $initech = ($this->fresh)($this->initech);

    expect($initech->industry)->toBe(CompanyIndustry::FinanceBanking)
        ->and($initech->employees)->toBe(85)
        ->and($initech->notes)->toBe('big')
        ->and(($this->addressOf)($this->initech)->street)->toBe('1 Main St')
        ->and($notification->data['body'])->toBe('Created: 0 | Updated: 1 | Failed: 0')
        ->and($notification->data['title'])->toBe('Import complete');
});

it('clears with a dash', function () {
    ($this->update)("name,website,type,employees,street,notes,VAT Number\nInitech,initech.com,-,-,-,-,-\n");
    $initech = ($this->fresh)($this->initech);

    expect($initech->company_type_id)->toBeNull()
        ->and($initech->employees)->toBeNull()
        ->and($initech->notes)->toBeNull()
        ->and($initech->custom_field_values)->not->toHaveKey($this->vat->key)
        ->and(($this->addressOf)($this->initech)->street)->toBeNull()
        ->and(($this->addressOf)($this->initech)->city)->toBe('Paris');
});

it('never changes the name and website of a company found by them', function () {
    ($this->update)("name,website\nINITECH,https://www.initech.com/about\n");

    expect(($this->fresh)($this->initech)->name)->toBe('Initech')
        ->and(($this->fresh)($this->initech)->website)->toBe('initech.com');
});

it('refuses a dash in the name, and in the website without an id', function (string $content, string $column) {
    $notification = ($this->update)($content);

    expect($notification->data['body'])->toContain("Row 1: The '{$column}' column can't be cleared.")
        ->and(($this->fresh)($this->initech)->website)->toBe('initech.com');
})->with([
    'website' => ["name,website\nInitech,-\n", 'website'],
    'name' => ["name,website\n-,initech.com\n", 'name'],
]);

it('refuses a dash in the name even with an id', function () {
    $notification = ($this->update)("id,name\n{$this->initech->id},-\n");

    expect($notification->data['body'])->toContain("The 'name' column can't be cleared.")
        ->and(($this->fresh)($this->initech)->name)->toBe('Initech');
});

it('gives a company found by name its missing website', function () {
    ($this->update)("name,website\nPlain Co,https://plain.example\n");

    $plain = ($this->fresh)($this->plain);

    expect($plain->website)->toBe('https://plain.example')
        ->and($plain->domain)->toBe('plain.example');
});

it('fails an update only row that matches nothing, and creates nothing', function (string $content, string $error) {
    $notification = ($this->update)($content);

    expect($notification->data['body'])->toContain("Row 1: {$error}")
        ->and(($this->count)())->toBe(3);
})->with([
    'name' => ["name\nNowhere Ltd\n", 'No company named Nowhere Ltd.'],
    'website' => ["name,website\nNowhere,https://www.nowhere.io/x\n", 'No company with the website nowhere.io.'],
]);

it('creates and updates in create and update', function () {
    $notification = ($this->upsert)("name,website\nInitech,initech.com\nNova,nova.io\n");

    expect(Company::forOrganization($this->org->id)->where('name', 'Nova')->exists())->toBeTrue()
        ->and($notification->data['body'])->toBe('Created: 1 | Updated: 1 | Failed: 0');

    $blank = ($this->upsert)("name,website,notes\n,,x\n");

    expect($blank->data['body'])->toContain('Name is required.');
});

it('creates a company named by a row whose website is new', function () {
    ($this->upsert)("name,website,notes\nZed,zed.io,hello\n");

    $zed = Company::forOrganization($this->org->id)->where('name', 'Zed')->sole();

    expect($zed->domain)->toBe('zed.io')
        ->and($zed->notes)->toBe('hello');
});

it('renames and changes the website of a company found by its id', function () {
    $notification = ($this->update)("id,name,website\n{$this->initech->id},Initech Global,initech.io\n");
    $initech = ($this->fresh)($this->initech);

    expect($initech->name)->toBe('Initech Global')
        ->and($initech->website)->toBe('initech.io')
        ->and($initech->domain)->toBe('initech.io')
        ->and($notification->data['body'])->toBe('Created: 0 | Updated: 1 | Failed: 0');
});

it('removes the website of a company found by its id', function () {
    ($this->update)("id,website\n{$this->initech->id},-\n");

    expect(($this->fresh)($this->initech)->website)->toBeNull()
        ->and(($this->fresh)($this->initech)->domain)->toBeNull();
});

it('refuses an invalid website or a name that is too long by id', function (string $name, string $website, string $error) {
    $cells = str_replace(['{name}', '{website}'], [$name, $website], "id,name,website\n{$this->initech->id},{name},{website}\n");

    $notification = ($this->update)($cells);

    expect($notification->data['body'])->toContain($error)
        ->and(($this->fresh)($this->initech)->name)->toBe('Initech');
})->with([
    'website without a domain' => ['Initech', 'not a site', "Invalid value for field 'website': not a site"],
    'name too long' => [str_repeat('a', 256), 'initech.com', "Invalid value for field 'name'"],
]);

it('does not match a later row through the memo of a website that changed', function () {
    $notification = ($this->update)("id,name,website\n,Initech,initech.com\n{$this->initech->id},,initech.io\n,Initech,initech.com\n");

    expect($notification->data['body'])->toContain('Created: 0 | Updated: 2 | Failed: 1')
        ->and($notification->data['body'])->toContain('Row 3: No company with the website initech.com.')
        ->and(($this->fresh)($this->initech)->website)->toBe('initech.io');
});

it('finds a company renamed by an earlier row through its new name', function () {
    $notification = ($this->update)("id,name,website\n{$this->initech->id},Initech Global,initech.io\n,Initech Global,initech.io\n");

    expect($notification->data['body'])->toBe('Created: 0 | Updated: 2 | Failed: 0');
});

it('fails the rows with a bad id', function (string $mode, string $idCell, string $error) {
    $other = Organization::factory()->create();
    $stranger = Company::factory()->create(['organization_id' => $other->id, 'name' => 'Stranger']);
    $gone = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Gone']);
    $gone->delete();
    $replace = fn (string $text): string => str_replace(['{stranger}', '{gone}'], [(string) $stranger->id, (string) $gone->id], $text);

    $notification = ($this->importFile)("id,name\n{$replace($idCell)},Zed\n", ImportMode::from($mode));

    expect($notification->data['body'])->toContain('Row 1: '.$replace($error))
        ->and(($this->count)())->toBe(4)
        ->and(Company::withTrashed()->withoutGlobalScope('organization')->where('name', 'Zed')->exists())->toBeFalse()
        ->and(($this->fresh)($stranger)->name)->toBe('Stranger');
})->with([
    'update, not a number' => ['update', 'abc', "Invalid value for field 'id': abc"],
    'create and update, not a number' => ['create_and_update', '1.5', "Invalid value for field 'id': 1.5"],
    'update, unknown' => ['update', '999999', 'No company with id 999999.'],
    'create and update, unknown' => ['create_and_update', '999999', 'No company with id 999999.'],
    'update, other organization' => ['update', '{stranger}', 'No company with id {stranger}.'],
    'create and update, other organization' => ['create_and_update', '{stranger}', 'No company with id {stranger}.'],
    'update, trashed' => ['update', '{gone}', 'The company with id {gone} is in the trash. Restore it first.'],
    'create and update, trashed' => ['create_and_update', '{gone}', 'The company with id {gone} is in the trash. Restore it first.'],
]);

it('reports what the matcher refuses', function () {
    Company::factory()->count(2)->create(['organization_id' => $this->org->id, 'name' => 'Twin', 'website' => null]);

    $notification = ($this->update)("name\nTwin\n");

    expect($notification->data['body'])->toContain('Row 1: Several companies are named Twin. Add the company website to choose.');
});

it('fails a company in the trash found by name', function () {
    $this->plain->delete();

    $notification = ($this->update)("name,phone\nPlain Co,+33 1\n");

    expect($notification->data['body'])->toContain('is in the trash. Restore it first.');
});

it('validates the values and changes nothing when one is invalid', function () {
    $notification = ($this->update)("name,website,industry,employees\nInitech,initech.com,Space,10\n");

    expect($notification->data['body'])->toContain("Invalid value for field 'industry': Space")
        ->and(($this->fresh)($this->initech)->employees)->toBe(85);
});

it('checks the unique custom field against the others, not the company itself', function () {
    $taken = ($this->update)("name,website,VAT Number\nInitech,initech.com,FR2\n");

    expect($taken->data['body'])->toContain("Duplicate value for unique field 'VAT Number'.")
        ->and(($this->fresh)($this->initech)->custom_field_values[$this->vat->key])->toBe('FR1');

    $own = ($this->update)("name,website,VAT Number,phone\nInitech,initech.com,FR1,+33 1\n");

    expect($own->data['body'])->toBe('Created: 0 | Updated: 1 | Failed: 0')
        ->and(($this->fresh)($this->initech)->phone)->toBe('+33 1');
});

it('creates the address of a company that has none, only when a cell has a value', function () {
    ($this->update)("name,website,city\nPlain Co,,-\n");

    expect(($this->fresh)($this->plain)->address_id)->toBeNull();

    ($this->update)("name,website,city\nPlain Co,,Lyon\n");

    $address = ($this->addressOf)($this->plain);

    expect($address->city)->toBe('Lyon')
        ->and($address->organization_id)->toBe($this->org->id);
});

it('changes only the given cells of an existing address', function () {
    ($this->update)("name,website,street\nInitech,initech.com,2 Side St\n");

    expect(($this->addressOf)($this->initech)->street)->toBe('2 Side St')
        ->and(($this->addressOf)($this->initech)->city)->toBe('Paris');
});

it('queues the sync of the segments when the type changes', function () {
    Queue::fake([SyncSegmentMembership::class]);
    $other = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Enterprise']);
    $segment = Segment::factory()->for($this->org)->published()->withRules([
        new SegmentRuleData('rule-1', 'Company', [
            SegmentConditionData::make(SegmentConditionType::Company, null, SegmentOperator::CompanyTypeIsAnyOf, ['values' => [$other->id]]),
        ]),
    ])->create();

    ($this->update)("name,website,type\nInitech,initech.com,Enterprise\n");

    expect(($this->fresh)($this->initech)->company_type_id)->toBe($other->id);
    Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => $job->segmentId === $segment->id);
});

it('never touches the company of another organization with the same name', function () {
    $other = Organization::factory()->create();
    $theirs = Company::factory()->create(['organization_id' => $other->id, 'name' => 'Initech', 'website' => 'initech.com', 'phone' => '000']);

    ($this->update)("name,website,phone\nInitech,initech.com,+33 1\n");

    expect(($this->fresh)($this->initech)->phone)->toBe('+33 1')
        ->and(($this->fresh)($theirs)->phone)->toBe('000');
});

it('names the created and updated companies when some rows fail', function () {
    $notification = ($this->upsert)("name,website,industry\nInitech,initech.com,\nNova,nova.io,\nBad,bad.io,Space\n");

    expect($notification->data['title'])->toBe('Import complete with errors')
        ->and($notification->data['body'])->toStartWith('Created: 1 | Updated: 1 | Failed: 1');
});

it('tells in update modes that rows were kept when the import crashes', function () {
    DatabaseNotification::query()->delete();
    (new ProcessCompanyImportJob('company-imports/none.csv', $this->org->id, $this->user->id, ImportMode::CreateAndUpdate))->failed(null);

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])
        ->toBe('Something went wrong while importing your file. Rows already imported or updated were kept; you can upload the file again.');

    DatabaseNotification::query()->delete();
    (new ProcessCompanyImportJob('company-imports/none.csv', $this->org->id, $this->user->id))->failed(null);

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toContain('existing companies will be reported as already existing');
});
