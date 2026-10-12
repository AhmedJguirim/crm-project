<?php

use App\Enums\ContactStatus;
use App\Enums\ImportMode;
use App\Enums\LeadSource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\ProcessContactImportJob;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use App\Services\ContactImportFileReader;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

test('list page loads successfully', function () {
    Livewire::test(ListContacts::class)
        ->assertSuccessful();
});

test('list page displays contacts for the organization', function () {
    $contacts = Contact::factory()->count(3)->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ListContacts::class)
        ->assertCanSeeTableRecords($contacts)
        ->assertCountTableRecords(3);
});

test('table displays required columns', function () {
    Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ListContacts::class)
        ->assertTableColumnExists('name')
        ->assertTableColumnExists('email')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('lead_source')
        ->assertTableColumnExists('created_at');
});

test('empty state is shown when no contacts exist', function () {
    Livewire::test(ListContacts::class)
        ->assertCountTableRecords(0);
});

test('header actions exist: create, importContacts, downloadTemplate', function () {
    Livewire::test(ListContacts::class)
        ->assertActionExists('create')
        ->assertActionExists('importContacts')
        ->assertActionExists('downloadTemplate');
});

test('the import action is labelled "Import Excel" and replaces importCsv', function () {
    Livewire::test(ListContacts::class)
        ->assertActionHasLabel('importContacts', 'Import Excel')
        ->assertActionDoesNotExist('importCsv');
});

test('accepted uploads queue the import', function (string $name, string $mimeType) {
    Storage::fake('local');
    Queue::fake();

    Livewire::test(ListContacts::class)
        ->callAction('importContacts', ['file' => UploadedFile::fake()->create($name, 10, $mimeType)])
        ->assertNotified('Import queued');

    Queue::assertPushed(ProcessContactImportJob::class, 1);
})->with([
    'csv' => ['contacts.csv', 'text/csv'],
    'xlsx' => ['contacts.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
]);

test('the import modal asks what the import should do, with create only by default', function () {
    Livewire::test(ListContacts::class)
        ->mountAction('importContacts')
        ->assertSchemaComponentExists('mode', 'mountedActionSchema0', function (Radio $field): bool {
            return $field->getLabel() === 'What should the import do?'
                && array_keys($field->getOptions()) === ['create', 'update', 'create_and_update']
                && $field->isRequired()
                && $field->getDefaultState() === ImportMode::CreateOnly;
        });
});

test('the chosen mode reaches the import job', function (?string $mode, ImportMode $expected) {
    Storage::fake('local');
    Queue::fake();

    Livewire::test(ListContacts::class)
        ->callAction('importContacts', ['file' => UploadedFile::fake()->create('contacts.csv', 10, 'text/csv'), ...($mode === null ? [] : ['mode' => $mode])])
        ->assertNotified('Import queued');

    Queue::assertPushed(ProcessContactImportJob::class, fn (ProcessContactImportJob $job): bool => (fn (): ImportMode => $this->mode)->call($job) === $expected);
})->with([
    'update' => ['update', ImportMode::UpdateOnly],
    'create and update' => ['create_and_update', ImportMode::CreateAndUpdate],
    'create' => ['create', ImportMode::CreateOnly],
    'none given' => [null, ImportMode::CreateOnly],
]);

test('a file over the limit is refused with a human message', function () {
    Storage::fake('local');
    Queue::fake();

    $page = Livewire::test(ListContacts::class)
        ->callAction('importContacts', ['file' => UploadedFile::fake()->create('big.csv', ContactImportFileReader::MAX_UPLOAD_KILOBYTES + 1000, 'text/csv')])
        ->assertHasActionErrors(['file']);

    $messages = collect($page->errors()->all());

    expect($messages->first())->toBe('This file is too large: the maximum is 10 MB. Tip: save it as .xlsx, which is much smaller than CSV.')
        ->and($messages->implode(' '))->not->toContain('mountedActions');
    Queue::assertNotPushed(ProcessContactImportJob::class);
});

test('a file just under the limit is accepted', function () {
    Storage::fake('local');
    Queue::fake();

    Livewire::test(ListContacts::class)
        ->callAction('importContacts', ['file' => UploadedFile::fake()->create('ok.csv', ContactImportFileReader::MAX_UPLOAD_KILOBYTES - 100, 'text/csv')])
        ->assertHasNoActionErrors();

    Queue::assertPushed(ProcessContactImportJob::class, 1);
});

test('the import limits are consistent and shown to the user', function () {
    $livewireMaximum = collect(config('livewire.temporary_file_upload.rules'))
        ->first(fn (string $rule): bool => str_starts_with($rule, 'max:'));

    Livewire::test(ListContacts::class)
        ->mountAction('importContacts')
        ->assertSchemaComponentExists('file', 'mountedActionSchema0', function (FileUpload $field): bool {
            $helperText = collect($field->getChildComponents($field::BELOW_CONTENT_SCHEMA_KEY))->map(fn ($component): string => (string) $component->getContent())->implode(' ');

            return $field->getMaxSize() === ContactImportFileReader::MAX_UPLOAD_KILOBYTES && str_contains($helperText, '10 MB');
        });

    expect((int) substr($livewireMaximum, 4))->toBeGreaterThan(ContactImportFileReader::MAX_UPLOAD_KILOBYTES)
        ->and(trans('validation.uploaded'))->not->toContain(':attribute');
});

test('an .xls upload is refused', function () {
    Storage::fake('local');
    Queue::fake();

    Livewire::test(ListContacts::class)
        ->callAction('importContacts', ['file' => UploadedFile::fake()->create('contacts.xls', 10, 'application/vnd.ms-excel')])
        ->assertNotified('Unsupported file type');

    Queue::assertNotPushed(ProcessContactImportJob::class);
});

// Search
test('table can be searched by name', function () {
    $matching = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Alice Smith',
    ]);

    $other = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Bob Jones',
    ]);

    Livewire::test(ListContacts::class)
        ->searchTable('Alice')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

test('table can be searched by email', function () {
    $matching = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'unique@example.com',
    ]);

    $other = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'other@test.com',
    ]);

    Livewire::test(ListContacts::class)
        ->searchTable('unique@example.com')
        ->assertCanSeeTableRecords([$matching])
        ->assertCanNotSeeTableRecords([$other]);
});

// Sorting
test('table can be sorted by name ascending', function () {
    Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Zara']);
    Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Aaron']);

    $sorted = Contact::where('organization_id', $this->org->id)->orderBy('name')->get();

    Livewire::test(ListContacts::class)
        ->sortTable('name')
        ->assertCanSeeTableRecords($sorted, inOrder: true);
});

test('table can be sorted by email', function () {
    Contact::factory()->create(['organization_id' => $this->org->id, 'email' => 'z@example.com', 'name' => 'Z']);
    Contact::factory()->create(['organization_id' => $this->org->id, 'email' => 'a@example.com', 'name' => 'A']);

    $sorted = Contact::where('organization_id', $this->org->id)->orderBy('email')->get();

    Livewire::test(ListContacts::class)
        ->sortTable('email')
        ->assertCanSeeTableRecords($sorted, inOrder: true);
});

// Tag filter
test('table can be filtered by tag', function () {
    $tag = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);

    $tagged = Contact::factory()->create(['organization_id' => $this->org->id]);
    $tagged->tags()->sync([$tag->id]);

    $untagged = Contact::factory()->create(['organization_id' => $this->org->id]);

    Livewire::test(ListContacts::class)
        ->filterTable('tags', [$tag->id])
        ->assertCanSeeTableRecords([$tagged])
        ->assertCanNotSeeTableRecords([$untagged]);
});

test('table can be filtered by status', function () {
    $activeClient = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'status' => ContactStatus::ActiveClient,
    ]);

    $prospect = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'status' => ContactStatus::Prospect,
    ]);

    Livewire::test(ListContacts::class)
        ->filterTable('status', [ContactStatus::ActiveClient->value])
        ->assertCanSeeTableRecords([$activeClient])
        ->assertCanNotSeeTableRecords([$prospect]);
});

test('table can be filtered by lead source', function () {
    $linkedIn = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'lead_source' => LeadSource::LinkedIn,
    ]);

    $referral = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'lead_source' => LeadSource::Referral,
    ]);

    Livewire::test(ListContacts::class)
        ->filterTable('lead_source', [LeadSource::LinkedIn->value])
        ->assertCanSeeTableRecords([$linkedIn])
        ->assertCanNotSeeTableRecords([$referral]);
});

// Organization isolation
test('user only sees contacts from their organization', function () {
    $ownContact = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'My Contact',
    ]);

    $otherUser = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $otherOrg = $otherUser->personalOrganization();

    $otherContact = Contact::factory()->create([
        'organization_id' => $otherOrg->id,
        'name' => 'Other Contact',
    ]);

    Livewire::test(ListContacts::class)
        ->assertCanSeeTableRecords([$ownContact])
        ->assertCanNotSeeTableRecords([$otherContact]);
});

// Bulk delete
test('user can bulk delete contacts', function () {
    $contacts = Contact::factory()->count(3)->create([
        'organization_id' => $this->org->id,
    ]);

    Livewire::test(ListContacts::class)
        ->selectTableRecords($contacts)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanNotSeeTableRecords($contacts);

    expect(Contact::where('organization_id', $this->org->id)->count())->toBe(0);
});

test('bulk delete only removes selected contacts', function () {
    $toDelete = Contact::factory()->count(2)->create([
        'organization_id' => $this->org->id,
    ]);

    $toKeep = Contact::factory()->create([
        'organization_id' => $this->org->id,
        'email' => 'keepme@example.com',
    ]);

    Livewire::test(ListContacts::class)
        ->selectTableRecords($toDelete)
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
        ->assertNotified()
        ->assertCanSeeTableRecords([$toKeep]);

    expect(Contact::find($toKeep->id))->not->toBeNull();
});
