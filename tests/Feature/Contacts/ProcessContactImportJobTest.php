<?php

use App\Jobs\ProcessContactImportJob;
use App\Jobs\SyncSegmentMembership;
use App\Models\Contact;
use App\Models\CustomField;
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
});

function makeCsv(string $content): string
{
    $path = 'contact-imports/test-'.uniqid().'.csv';
    Storage::disk('local')->put($path, $content);

    return $path;
}

// Successful import
test('valid CSV creates contacts and sends success notification', function () {
    $csv = "name,email,phone,tags\nJohn Doe,john@example.com,+1234567890,VIP\nJane Smith,jane@example.com,,\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect(Contact::where('organization_id', $this->org->id)->count())->toBe(2);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['title'])->toBe('Import complete')
        ->and($notification->data['body'])->toContain('Imported: 2 | Failed: 0');
});

test('CSV file is deleted after processing', function () {
    $csv = "name,email,phone,tags\nJohn,john@example.com,,\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect(Storage::disk('local')->exists($path))->toBeFalse();
});

// Duplicate email is rejected (not upserted)
test('duplicate email in CSV records the second row as failed', function () {
    $csv = "name,email,phone,tags\nFirst,dup@example.com,,\nSecond,dup@example.com,,\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect(Contact::where('email', 'dup@example.com')->count())->toBe(1);
    expect(Contact::where('email', 'dup@example.com')->first()->name)->toBe('First');

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
    expect($notification->data['title'])->toBe('Import complete with errors')
        ->and($notification->data['body'])->toContain('Imported: 1 | Failed: 1');
});

// Failed rows
test('failed rows trigger a warning notification with error details', function () {
    $csv = "name,email,phone,tags\n,invalid-email,,\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
    expect($notification)->not->toBeNull()
        ->and($notification->data['title'])->toBe('Import complete with errors')
        ->and($notification->data['body'])->toContain('Failed: 1');
});

test('failed rows CSV is created and notification includes download action', function () {
    $csv = "name,email,phone,tags\nTest,bad-email,,\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
    $actions = $notification->data['actions'] ?? [];

    expect(count($actions))->toBeGreaterThan(0);
    expect($actions[0]['label'])->toBe('Download failed rows');
    expect($actions[0]['url'])->toContain('contacts/import/failed-rows');

    // The failed rows CSV file should exist
    $urlParts = parse_url($actions[0]['url']);
    parse_str($urlParts['query'] ?? '', $queryParams);
    $failedPath = $queryParams['path'] ?? '';

    expect(Storage::disk('local')->exists($failedPath))->toBeTrue();
});

test('failed rows CSV contains correct headers and error column', function () {
    $csv = "name,email,phone,tags\n,bad-email,,\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
    $urlParts = parse_url($notification->data['actions'][0]['url']);
    parse_str($urlParts['query'] ?? '', $queryParams);

    $csvContent = Storage::disk('local')->get($queryParams['path']);
    expect($csvContent)->toContain('_row_number')
        ->toContain('_error')
        ->toContain('name')
        ->toContain('email');
});

test('partial failure sends warning with correct counts', function () {
    $csv = "name,email,phone,tags\nJohn,john@example.com,,\n,bad-email,,\nJane,jane@example.com,,\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect(Contact::where('organization_id', $this->org->id)->count())->toBe(2);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
    expect($notification->data['body'])->toContain('Imported: 2 | Failed: 1');
});

// BOM handling
test('UTF-8 BOM in CSV header is stripped and processed correctly', function () {
    $bom = "\xEF\xBB\xBF";
    $csv = "{$bom}name,email,phone,tags\nBOM Contact,bom@example.com,,\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect(Contact::where('email', 'bom@example.com')->exists())->toBeTrue();
});

// Blank row skip
test('blank rows in CSV are skipped silently', function () {
    $csv = "name,email,phone,tags\nJohn,john@example.com,,\n\n\nJane,jane@example.com,,\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect(Contact::where('organization_id', $this->org->id)->count())->toBe(2);
});

// Column count mismatch
test('row with wrong column count is recorded as failed', function () {
    $csv = "name,email,phone,tags\nJohn,john@example.com\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect(Contact::where('organization_id', $this->org->id)->count())->toBe(0);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
    expect($notification->data['body'])->toContain('Failed: 1');
});

// Tag auto-creation in job context — uses semicolon separator
test('single tag in CSV is auto-created in the correct organization', function () {
    $csv = "name,email,phone,tags\nTagged,tagged@example.com,,NewTag\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect(Tag::where('organization_id', $this->org->id)->where('name', 'NewTag')->exists())->toBeTrue();

    $contact = Contact::where('email', 'tagged@example.com')->first();
    expect($contact->tags()->count())->toBe(1);
});

test('multiple semicolon-separated tags in CSV are auto-created', function () {
    $csv = "name,email,phone,tags\nTagged,tagged@example.com,,VIP;Newsletter\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    $contact = Contact::where('email', 'tagged@example.com')->first();
    expect($contact->tags()->count())->toBe(2);
});

// Custom fields in CSV
test('CSV with custom field columns are processed correctly', function () {
    $field = CustomField::factory()->create([
        'organization_id' => $this->org->id,
        'name' => 'Company',
        'type' => 'text',
        'unique' => false,
        'order' => 1,
    ]);

    $csv = "name,email,phone,tags,Company\nCorp Contact,corp@example.com,,,Acme\n";
    $path = makeCsv($csv);

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    $contact = Contact::where('email', 'corp@example.com')->first();
    expect($contact->custom_field_values[$field->key])->toBe('Acme');
});

// Excel import
describe('xlsx imports', function () {
    test('an xlsx import creates contacts like a csv import does', function () {
        $score = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Score', 'type' => 'number', 'unique' => false, 'order' => 1]);
        $birthday = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Birthday', 'type' => 'date', 'unique' => false, 'order' => 2]);
        $interests = CustomField::factory()->multiselect()->create(['organization_id' => $this->org->id, 'name' => 'Interests', 'unique' => false, 'order' => 3]);
        Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);

        $path = makeXlsx([
            ['name', 'email', 'phone', 'tags', 'Score', 'Birthday', 'Interests'],
            ['Jane Doe', 'jane@example.com', 216555012, 'VIP', 7, new DateTimeImmutable('1990-03-14'), 'tag1;tag2'],
        ]);

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        $contact = Contact::where('organization_id', $this->org->id)->where('email', 'jane@example.com')->first();

        expect($contact)->not->toBeNull()
            ->and($contact->phone)->toBe('216555012')
            ->and($contact->customFieldValue($score->key))->toEqual(7)
            ->and($contact->customFieldValue($birthday->key))->toBe('1990-03-14')
            ->and($contact->customFieldValue($interests->key))->toEqual(['tag1', 'tag2'])
            ->and($contact->tags()->pluck('name')->all())->toBe(['VIP'])
            ->and(DatabaseNotification::where('notifiable_id', $this->user->id)->first()->data['body'])->toContain('Imported: 1 | Failed: 0')
            ->and(Storage::disk('local')->exists($path))->toBeFalse();
    });

    test('rows shorter than the header are padded and longer rows fail', function () {
        $path = makeXlsx([
            ['name', 'email', 'phone', 'tags'],
            ['Jane', 'jane@example.com', '123', 'VIP'],
            ['John', 'john@example.com'],
            ['Too', 'long@example.com', '', '', 'extra'],
        ]);

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        expect(Contact::where('organization_id', $this->org->id)->orderBy('email')->pluck('email')->all())->toBe(['jane@example.com', 'john@example.com'])
            ->and(Contact::where('email', 'john@example.com')->first()->tags()->count())->toBe(0);

        $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
        expect($notification->data['body'])->toContain('Imported: 2 | Failed: 1')
            ->and($notification->data['body'])->toContain('Row 3: Column count mismatch.');
    });

    test('failed xlsx rows are reported in a csv', function () {
        $path = makeXlsx([
            ['name', 'email', 'phone', 'tags'],
            ['Jane', 'jane@example.com', '', ''],
            ['Bad', 'not-an-email', '', ''],
        ]);

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();
        $failedCsv = collect(Storage::disk('local')->files('contact-imports'))->first(fn (string $file): bool => str_contains($file, 'failed-'));

        expect($notification->data['title'])->toBe('Import complete with errors')
            ->and($notification->data['actions'][0]['name'])->toBe('downloadFailedRows')
            ->and($failedCsv)->not->toBeNull()
            ->and(Storage::disk('local')->get($failedCsv))->toContain('Invalid email: not-an-email');
    });

    test('an unreadable file fails gracefully', function () {
        Queue::fake([SyncSegmentMembership::class]);
        Segment::factory()->for($this->org)->published()->create();
        $path = 'contact-imports/contacts.xlsx';
        Storage::disk('local')->put($path, 'this is not a spreadsheet');

        ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

        $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->first();

        expect(Contact::where('organization_id', $this->org->id)->count())->toBe(0)
            ->and($notification->data['title'])->toBe('Import failed')
            ->and(Storage::disk('local')->exists($path))->toBeFalse();
        Queue::assertNotPushed(SyncSegmentMembership::class);
    });
});
