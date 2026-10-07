<?php

use App\Enums\ContactStatus;
use App\Enums\ImportMode;
use App\Enums\LeadSource;
use App\Jobs\ProcessContactImportJob;
use App\Jobs\SyncSegmentMembership;
use App\Models\Address;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Organization;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();

    $this->plan = CustomField::factory()->select()->for($this->org)->create(['name' => 'Plan', 'unique' => false, 'options' => [['label' => 'Free', 'value' => 'free'], ['label' => 'Pro', 'value' => 'pro']]]);
    $this->seats = CustomField::factory()->for($this->org)->create(['name' => 'Seats', 'type' => 'number', 'unique' => false]);
    $this->badge = CustomField::factory()->for($this->org)->create(['name' => 'Badge', 'type' => 'text', 'unique' => true]);

    $this->vip = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);

    $this->ann = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Ann Old',
        'email' => 'ann@example.test',
        'phone' => '+33 1 11',
        'status' => ContactStatus::ActiveClient,
        'lead_source' => LeadSource::Referral,
        'custom_field_values' => [$this->plan->key => 'pro', $this->seats->key => 5, $this->badge->key => 'A-1'],
    ]);
    $this->ann->tags()->sync([$this->vip->id]);

    $this->bob = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Bob',
        'email' => 'bob@example.test',
        'phone' => null,
        'status' => ContactStatus::Lead,
        'lead_source' => null,
        'custom_field_values' => [],
    ]);

    $this->importFile = function (string $content, ImportMode $mode): DatabaseNotification {
        $path = 'contact-imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $content);
        DatabaseNotification::query()->delete();

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id, $mode);

        return DatabaseNotification::where('notifiable_id', $this->user->id)->latest('created_at')->firstOrFail();
    };

    $this->update = fn (string $content): DatabaseNotification => ($this->importFile)($content, ImportMode::UpdateOnly);
    $this->upsert = fn (string $content): DatabaseNotification => ($this->importFile)($content, ImportMode::CreateAndUpdate);
    $this->fresh = fn (Contact $contact): Contact => Contact::withTrashed()->withoutGlobalScope('organization')->findOrFail($contact->id);
    $this->count = fn (): int => Contact::withTrashed()->withoutGlobalScope('organization')->where('organization_id', $this->org->id)->count();
    $this->tagNames = fn (Contact $contact): array => $contact->tags()->withoutGlobalScopes()->pluck('name')->sort()->values()->all();
    $this->companyNames = fn (Contact $contact): array => $contact->companies()->withoutGlobalScopes()->pluck('name')->sort()->values()->all();
    $this->untouched = fn (Contact $contact): array => $contact->only(['name', 'email', 'phone', 'status', 'lead_source', 'custom_field_values']);
});

describe('the mode enum', function () {
    it('tells what each mode does', function () {
        expect(ImportMode::CreateOnly->updatesExisting())->toBeFalse()
            ->and(ImportMode::CreateOnly->createsNew())->toBeTrue()
            ->and(ImportMode::UpdateOnly->updatesExisting())->toBeTrue()
            ->and(ImportMode::UpdateOnly->createsNew())->toBeFalse()
            ->and(ImportMode::CreateAndUpdate->updatesExisting())->toBeTrue()
            ->and(ImportMode::CreateAndUpdate->createsNew())->toBeTrue()
            ->and(ImportMode::CreateOnly->getLabel())->toBe('Create only')
            ->and(ImportMode::UpdateOnly->getDescription())->toBe('Updates the contacts that already exist. A contact that is not found is reported as failed.');
    });

    it('reads the state of the form, a case or a value', function () {
        expect(ImportMode::fromState(ImportMode::UpdateOnly))->toBe(ImportMode::UpdateOnly)
            ->and(ImportMode::fromState('create_and_update'))->toBe(ImportMode::CreateAndUpdate)
            ->and(ImportMode::fromState('create'))->toBe(ImportMode::CreateOnly);
    });
});

it('keeps create only as it was', function () {
    $notification = ($this->importFile)("name,email\nAnn New,ann@example.test\n", ImportMode::CreateOnly);

    expect($notification->data['body'])->toStartWith('Imported: 0 | Failed: 1')
        ->and($notification->data['body'])->toContain("A contact with email 'ann@example.test' already exists.")
        ->and(($this->fresh)($this->ann)->name)->toBe('Ann Old');
});

it('never reads the id column in create only', function () {
    $notification = ($this->importFile)("id,name,email\n{$this->ann->id},Zed,zed@example.test\n", ImportMode::CreateOnly);

    expect($notification->data['body'])->toBe('Imported: 1 | Failed: 0')
        ->and(($this->fresh)($this->ann)->name)->toBe('Ann Old')
        ->and(Contact::forOrganization($this->org->id)->where('email', 'zed@example.test')->exists())->toBeTrue();
});

it('updates by email and leaves the values of blank cells', function () {
    $notification = ($this->update)("name,email,phone,status,Plan,Seats\nAnn Renamed,ann@example.test,,,,\n");
    $ann = ($this->fresh)($this->ann);

    expect($ann->name)->toBe('Ann Renamed')
        ->and($ann->phone)->toBe('+33 1 11')
        ->and($ann->status)->toBe(ContactStatus::ActiveClient)
        ->and($ann->custom_field_values[$this->plan->key])->toBe('pro')
        ->and($ann->custom_field_values[$this->seats->key])->toEqual(5)
        ->and($notification->data['title'])->toBe('Import complete')
        ->and($notification->data['body'])->toBe('Created: 0 | Updated: 1 | Failed: 0');
});

it('replaces a value and clears with a dash', function () {
    ($this->update)("email,phone,lead source,Plan,Seats\nann@example.test,-,-,-,12\n");
    $ann = ($this->fresh)($this->ann);

    expect($ann->phone)->toBeNull()
        ->and($ann->lead_source)->toBeNull()
        ->and($ann->custom_field_values)->not->toHaveKey($this->plan->key)
        ->and($ann->custom_field_values[$this->seats->key])->toEqual(12)
        ->and($ann->custom_field_values[$this->badge->key])->toBe('A-1');
});

it('refuses a dash in a column that can not be cleared', function (string $column) {
    $content = $column === 'email'
        ? "id,email\n{$this->ann->id},-\n"
        : "email,{$column}\nann@example.test,-\n";
    $before = ($this->untouched)(($this->fresh)($this->ann));

    $notification = ($this->update)($content);

    expect($notification->data['body'])->toContain("Row 1: The '{$column}' column can't be cleared.")
        ->and(($this->untouched)(($this->fresh)($this->ann)))->toEqual($before);
})->with(['name', 'email', 'status', 'tags', 'company', 'company website']);

it('refuses a dash in a column that can not be cleared even when the contact does not exist', function () {
    $notification = ($this->upsert)("name,email\n-,nobody@example.test\n");

    expect($notification->data['body'])->toContain("The 'name' column can't be cleared.")
        ->and(($this->count)())->toBe(2);
});

it('only adds tags', function () {
    ($this->update)("email,tags\nann@example.test,Newsletter;VIP\n");

    expect(($this->tagNames)(($this->fresh)($this->ann)))->toBe(['Newsletter', 'VIP']);

    ($this->update)("email,tags\nann@example.test,\n");

    expect(($this->tagNames)(($this->fresh)($this->ann)))->toBe(['Newsletter', 'VIP']);
});

it('restores a trashed tag it adds', function () {
    $old = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'Old']);
    $old->delete();

    ($this->update)("email,tags\nbob@example.test,Old\n");

    expect(($this->tagNames)(($this->fresh)($this->bob)))->toBe(['Old'])
        ->and(Tag::withTrashed()->withoutGlobalScope('organization')->find($old->id)->trashed())->toBeFalse();
});

it('fails an unknown email in update only and creates nothing', function () {
    $notification = ($this->update)("email\nnobody@example.test\n");

    expect($notification->data['body'])->toContain("Row 1: No contact with email 'nobody@example.test'.")
        ->and(($this->count)())->toBe(2);
});

it('requires a valid email to find a contact', function (string $cell, string $error) {
    $notification = ($this->update)("name,email\nX,{$cell}\n");

    expect($notification->data['body'])->toContain($error);
})->with([
    'blank' => ['', 'Email is required.'],
    'invalid' => ['not-an-email', 'Invalid email: not-an-email'],
]);

it('creates and updates in create and update', function () {
    $notification = ($this->upsert)("name,email\nAnn Two,ann@example.test\nCarl,carl@example.test\n");
    $carl = Contact::forOrganization($this->org->id)->where('email', 'carl@example.test')->sole();

    expect(($this->fresh)($this->ann)->name)->toBe('Ann Two')
        ->and($carl->status)->toBe(ContactStatus::Lead)
        ->and($notification->data['body'])->toBe('Created: 1 | Updated: 1 | Failed: 0');
});

it('keeps the status of an existing contact when the cell is blank, and gives a new one Lead', function () {
    ($this->upsert)("name,email,status\nAnn,ann@example.test,\nDan,dan@example.test,\n");

    expect(($this->fresh)($this->ann)->status)->toBe(ContactStatus::ActiveClient)
        ->and(Contact::forOrganization($this->org->id)->where('email', 'dan@example.test')->sole()->status)->toBe(ContactStatus::Lead);
});

it('lets the id win over the email', function () {
    $before = [($this->untouched)(($this->fresh)($this->ann)), ($this->untouched)(($this->fresh)($this->bob))];

    $notification = ($this->update)("id,email,name\n{$this->ann->id},bob@example.test,Ann By Id\n");

    expect($notification->data['body'])->toContain("A contact with email 'bob@example.test' already exists.")
        ->and([($this->untouched)(($this->fresh)($this->ann)), ($this->untouched)(($this->fresh)($this->bob))])->toEqual($before);

    ($this->update)("id,email,name\n{$this->ann->id},,Ann By Id\n");

    expect(($this->fresh)($this->ann)->name)->toBe('Ann By Id')
        ->and(($this->fresh)($this->ann)->email)->toBe('ann@example.test');
});

it('never falls back to the email when the id is not found', function () {
    $notification = ($this->upsert)("id,email,name\n999999,ann@example.test,Hijack\n");

    expect($notification->data['body'])->toContain('No contact with id 999999.')
        ->and(($this->fresh)($this->ann)->name)->toBe('Ann Old')
        ->and(($this->count)())->toBe(2);
});

it('corrects an email through the id, lowercased', function () {
    ($this->update)("id,email\n{$this->ann->id},ANN.NEW@example.test\n");

    expect(($this->fresh)($this->ann)->email)->toBe('ann.new@example.test');
});

it('refuses the email of a deleted contact when correcting an email', function () {
    $this->bob->delete();

    $notification = ($this->update)("id,email\n{$this->ann->id},bob@example.test\n");

    expect($notification->data['body'])->toContain("A contact with email 'bob@example.test' already exists.")
        ->and(($this->fresh)($this->ann)->email)->toBe('ann@example.test');
});

it('refuses an invalid email correction', function () {
    $notification = ($this->update)("id,email\n{$this->ann->id},nope\n");

    expect($notification->data['body'])->toContain('Invalid email: nope');
});

it('fails the rows with a bad id', function (string $mode, string $idCell, string $error) {
    $other = Organization::factory()->create();
    $stranger = Contact::factory()->create(['organization_id' => $other->id, 'name' => 'Stranger', 'email' => 'ann@example.test']);
    $gone = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Gone', 'email' => 'gone@example.test']);
    $gone->delete();
    $cell = str_replace(['{stranger}', '{gone}'], [(string) $stranger->id, (string) $gone->id], $idCell);

    $notification = ($this->importFile)("id,name,email\n{$cell},Zed,zed@example.test\n", ImportMode::from($mode));

    expect($notification->data['body'])->toContain('Row 1: '.str_replace(['{stranger}', '{gone}'], [(string) $stranger->id, (string) $gone->id], $error))
        ->and(Contact::withTrashed()->withoutGlobalScope('organization')->where('organization_id', $this->org->id)->count())->toBe(3)
        ->and(Contact::withTrashed()->withoutGlobalScope('organization')->where('email', 'zed@example.test')->exists())->toBeFalse();
})->with([
    'update, not a number' => ['update', 'abc', "Invalid value for field 'id': abc"],
    'create and update, not a number' => ['create_and_update', '1.5', "Invalid value for field 'id': 1.5"],
    'update, unknown' => ['update', '999999', 'No contact with id 999999.'],
    'create and update, unknown' => ['create_and_update', '999999', 'No contact with id 999999.'],
    'update, other organization' => ['update', '{stranger}', 'No contact with id {stranger}.'],
    'create and update, other organization' => ['create_and_update', '{stranger}', 'No contact with id {stranger}.'],
    'update, deleted' => ['update', '{gone}', 'The contact with id {gone} is deleted. Restore it from the trash first.'],
    'create and update, deleted' => ['create_and_update', '{gone}', 'The contact with id {gone} is deleted. Restore it from the trash first.'],
]);

it('refuses a deleted contact found by email, in both modes', function (ImportMode $mode) {
    $this->bob->delete();

    $notification = ($this->importFile)("email\nbob@example.test\n", $mode);

    expect($notification->data['body'])->toContain("A deleted contact with email 'bob@example.test' exists. Restore it from the trash first.")
        ->and(($this->fresh)($this->bob)->trashed())->toBeTrue();
})->with([ImportMode::UpdateOnly, ImportMode::CreateAndUpdate]);

it('changes nothing when a value is invalid', function () {
    $notification = ($this->update)("email,Plan,phone\nann@example.test,Gold,+33 9\n");

    expect($notification->data['body'])->toContain("Invalid value for field 'Plan': Gold")
        ->and(($this->fresh)($this->ann)->phone)->toBe('+33 1 11');
});

it('fails an invalid status and lead source', function (string $column) {
    $notification = ($this->update)("email,{$column}\nann@example.test,nonsense\n");

    expect($notification->data['body'])->toContain("Invalid value for field '{$column}': nonsense");
})->with(['status', 'lead source']);

it('reads a status and a lead source by label and sets them', function () {
    ($this->update)("email,status,lead source\nbob@example.test,Active Client,LinkedIn\n");

    expect(($this->fresh)($this->bob)->status)->toBe(ContactStatus::ActiveClient)
        ->and(($this->fresh)($this->bob)->lead_source)->toBe(LeadSource::LinkedIn);
});

it('checks the unique custom fields against the others, not the contact itself', function () {
    $this->bob->update(['custom_field_values' => [$this->badge->key => 'B-1']]);

    $taken = ($this->update)("email,Badge\nann@example.test,B-1\n");

    expect($taken->data['body'])->toContain("Duplicate value for unique field 'Badge'.")
        ->and(($this->fresh)($this->ann)->custom_field_values[$this->badge->key])->toBe('A-1');

    $own = ($this->update)("email,Badge,Seats\nann@example.test,A-1,9\n");

    expect($own->data['body'])->toBe('Created: 0 | Updated: 1 | Failed: 0')
        ->and(($this->fresh)($this->ann)->custom_field_values[$this->seats->key])->toEqual(9);
});

describe('the companies of an update', function () {
    beforeEach(function () {
        $this->acme = Company::factory()->create([
            'organization_id' => $this->org->id,
            'name' => 'Acme',
            'website' => 'acme.com',
            'address_id' => Address::factory()->create(['organization_id' => $this->org->id, 'city' => 'Paris'])->id,
        ]);
    });

    it('links an existing company without changing it, and says so', function () {
        $notification = ($this->update)("email,company,company website,company city\nbob@example.test,Acme,acme.com,Lyon\n");

        expect(($this->companyNames)(($this->fresh)($this->bob)))->toBe(['Acme'])
            ->and(Address::withoutGlobalScopes()->find($this->acme->address_id)->city)->toBe('Paris')
            ->and($notification->data['body'])->toContain('Company details were not changed for existing companies.');
    });

    it('creates a new company and keeps the other links', function () {
        $this->bob->companies()->attach($this->acme->id);

        $notification = ($this->update)("email,company,company city\nbob@example.test,Nova,Lyon\n");

        expect(($this->companyNames)(($this->fresh)($this->bob)))->toBe(['Acme', 'Nova'])
            ->and(Address::withoutGlobalScopes()->find(Company::forOrganization($this->org->id)->where('name', 'Nova')->sole()->address_id)->city)->toBe('Lyon')
            ->and($notification->data['body'])->toContain('Companies created: 1');
    });

    it('never links twice', function () {
        $this->bob->companies()->attach($this->acme->id);

        ($this->update)("email,company,company website\nbob@example.test,Acme,acme.com\n");

        expect(($this->companyNames)(($this->fresh)($this->bob)))->toBe(['Acme']);
    });

    it('fails the row for a company in the trash and changes nothing', function () {
        $this->acme->delete();

        $notification = ($this->update)("email,phone,company,company website\nbob@example.test,+33 7,Acme,acme.com\n");

        expect($notification->data['body'])->toContain('Row 1:')
            ->and(($this->fresh)($this->bob)->phone)->toBeNull()
            ->and(($this->companyNames)(($this->fresh)($this->bob)))->toBe([]);
    });

    it('creates no company when the row fails on another cell', function () {
        ($this->update)("email,Plan,company\nbob@example.test,Gold,Nova\n");

        expect(Company::forOrganization($this->org->id)->where('name', 'Nova')->exists())->toBeFalse();
    });
});

it('queues the sync of the published segments after an update', function () {
    Queue::fake([SyncSegmentMembership::class]);
    $segment = Segment::factory()->for($this->org)->published()->create();

    ($this->update)("email,Plan\nbob@example.test,Pro\n");

    Queue::assertPushed(SyncSegmentMembership::class, fn (SyncSegmentMembership $job): bool => (fn () => $this->segmentId)->call($job) === $segment->id);
});

it('does not queue a sync when no row succeeded', function () {
    Queue::fake([SyncSegmentMembership::class]);
    Segment::factory()->for($this->org)->published()->create();

    ($this->update)("email\nnobody@example.test\n");

    Queue::assertNotPushed(SyncSegmentMembership::class);
});

it('never touches the contact of another organization with the same email', function () {
    $other = Organization::factory()->create();
    $theirs = Contact::factory()->create(['organization_id' => $other->id, 'name' => 'Theirs', 'email' => 'bob@example.test']);

    ($this->update)("email,name\nbob@example.test,Mine\n");

    expect(($this->fresh)($this->bob)->name)->toBe('Mine')
        ->and(Contact::withoutGlobalScopes()->find($theirs->id)->name)->toBe('Theirs');
});

it('names the created and updated contacts when some rows fail', function () {
    $notification = ($this->upsert)("name,email,status\nAnn Two,ann@example.test,\nCarl,carl@example.test,\nBad,bad@example.test,nonsense\n");

    expect($notification->data['title'])->toBe('Import complete with errors')
        ->and($notification->data['body'])->toStartWith('Created: 1 | Updated: 1 | Failed: 1');
});

it('keeps the id column in the failed rows file', function () {
    ($this->update)("id,email,Plan\n{$this->ann->id},ann@example.test,Gold\n");

    $file = Storage::disk('local')->files('contact-imports');

    expect(collect($file)->contains(fn (string $path): bool => str_contains(Storage::disk('local')->get($path), '_row_number,_error,id,email,Plan')))->toBeTrue();
});

it('tells in update modes that rows were kept when the import crashes', function () {
    $job = new ProcessContactImportJob('contact-imports/none.csv', $this->org->id, $this->user->id, ImportMode::UpdateOnly);
    Queue::fake([SyncSegmentMembership::class]);
    DatabaseNotification::query()->delete();

    $job->failed(null);

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])
        ->toBe('Something went wrong while importing your file. Rows already imported or updated were kept; you can upload the file again.');

    DatabaseNotification::query()->delete();
    (new ProcessContactImportJob('contact-imports/none.csv', $this->org->id, $this->user->id))->failed(null);

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toContain('existing contacts will be reported as already existing');
});
