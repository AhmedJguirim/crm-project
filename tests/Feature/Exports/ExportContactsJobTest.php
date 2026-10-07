<?php

use App\Jobs\ExportContactsJob;
use App\Jobs\Middleware\WithTenantContext;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Tag;
use App\Models\User;
use App\Support\TemporaryFile;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Spatie\SimpleExcel\SimpleExcelReader;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();

    $this->contact = fn (string $name, array $attributes = []): Contact => Contact::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        'email' => strtolower($name).'@x.test',
        ...$attributes,
    ]);

    $this->exportNames = function (array $ids, string $format = 'xlsx', ?int $organizationId = null): array {
        DatabaseNotification::query()->delete();
        ExportContactsJob::dispatchSync($ids, $organizationId ?? $this->org->id, $this->user->id, $format, "contacts-2026-10-06.{$format}");

        $file = Storage::disk('local')->files('exports')[0];
        $rows = SimpleExcelReader::create(Storage::disk('local')->path($file), $format)->getRows()->all();

        return array_column($rows, 'name');
    };
});

it('writes the contacts in the order of the given ids, including trashed ones', function () {
    $ann = ($this->contact)('Ann');
    $bob = ($this->contact)('Bob');
    $dan = ($this->contact)('Dan');
    $dan->delete();

    expect(($this->exportNames)([$dan->id, $ann->id, $bob->id]))->toBe(['Dan', 'Ann', 'Bob']);
});

it('reads the contacts in chunks of 500 and keeps the order across chunks', function () {
    $ids = [];

    foreach (range(1, 503) as $number) {
        $ids[] = ($this->contact)("Contact {$number}")->id;
    }

    $ids = array_reverse($ids);
    $names = ($this->exportNames)($ids, 'csv');

    expect($names)->toHaveCount(503)
        ->and($names[0])->toBe('Contact 503')
        ->and($names[499])->toBe('Contact 4')
        ->and($names[502])->toBe('Contact 1');
});

it('exports only contacts of its organization, whatever ids it is given', function () {
    $mine = ($this->contact)('Mine');
    $foreign = Contact::factory()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'Foreign']);

    expect(($this->exportNames)([$foreign->id, $mine->id]))->toBe(['Mine']);
});

it('writes the tags and companies of the contacts, working only from its tenant context', function () {
    $ann = ($this->contact)('Ann');
    $ann->tags()->sync([Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP'])->id]);
    $ann->companies()->attach(Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme', 'website' => 'acme.com'])->id);

    ($this->exportNames)([$ann->id], 'csv');

    $row = SimpleExcelReader::create(Storage::disk('local')->path(Storage::disk('local')->files('exports')[0]), 'csv')->getRows()->first();

    expect(Filament\Facades\Filament::getTenant())->toBeNull()
        ->and($row['tags'])->toBe('VIP')
        ->and($row['company'])->toBe('Acme')
        ->and($row['company website'])->toBe('acme.com');
});

it('skips a contact that no longer exists', function () {
    $ann = ($this->contact)('Ann');

    expect(($this->exportNames)([999999, $ann->id], 'csv'))->toBe(['Ann']);
});

it('tells the user the export is ready with a signed download link', function () {
    $ids = [($this->contact)('Ann')->id, ($this->contact)('Bob')->id, ($this->contact)('Cid')->id];

    ($this->exportNames)($ids);

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();
    $url = $notification->data['actions'][0]['url'];
    $file = basename(Storage::disk('local')->files('exports')[0]);

    expect($notification->data['title'])->toBe('Export ready')
        ->and($notification->data['body'])->toBe('3 contacts')
        ->and($notification->data['actions'][0]['label'])->toBe('Download')
        ->and($notification->data['actions'][0]['shouldOpenUrlInNewTab'])->toBeTrue()
        ->and($file)->toMatch('/^contacts-[A-Za-z0-9]{40}\.xlsx$/')
        ->and($url)->toContain('signature=')
        ->and($url)->toContain("file={$file}")
        ->and($url)->toContain('name=contacts-2026-10-06.xlsx')
        ->and($url)->toContain("user={$this->user->id}");

    $this->actingAs($this->user)->get($url)->assertOk()->assertDownload('contacts-2026-10-06.xlsx');
});

it('makes the download link valid for exactly 7 days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));

    ($this->exportNames)([($this->contact)('Ann')->id]);

    $url = DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['actions'][0]['url'];
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect((int) $query['expires'])->toBe(CarbonImmutable::parse('2026-10-14 10:00:00')->getTimestamp());
});

it('says "1 contact" for a single contact', function () {
    ($this->exportNames)([($this->contact)('Ann')->id], 'csv');

    expect(DatabaseNotification::where('notifiable_id', $this->user->id)->sole()->data['body'])->toBe('1 contact');
});

it('leaves no temporary file behind', function () {
    $directory = sys_get_temp_dir().'/crm-export-tmp-'.uniqid();
    mkdir($directory);
    TemporaryFile::useDirectory($directory);

    ($this->exportNames)([($this->contact)('Ann')->id], 'csv');

    $left = glob("{$directory}/*");
    TemporaryFile::useDirectory(null);
    rmdir($directory);

    expect($left)->toBe([]);
});

it('tells the user when the export fails', function () {
    (new ExportContactsJob([1], $this->org->id, $this->user->id, 'xlsx', 'contacts.xlsx'))->failed(new RuntimeException('boom'));

    $notification = DatabaseNotification::where('notifiable_id', $this->user->id)->sole();

    expect($notification->data['title'])->toBe('Export failed')
        ->and($notification->data['body'])->toBe('Something went wrong while building the file. Try again, or contact support if it keeps failing.');
});

it('runs on a queue a Horizon supervisor listens to, with a timeout the supervisor outlives', function () {
    $job = new ExportContactsJob([1], $this->org->id, $this->user->id, 'xlsx', 'contacts.xlsx');
    $supervisor = collect(config('horizon.defaults'))->first(fn (array $supervisor): bool => in_array($job->queue, $supervisor['queue'], true));

    expect($job->connection)->toBe(config('queue.long_running_connection'))
        ->and($supervisor['connection'])->toBe('redis-long')
        ->and($supervisor['timeout'])->toBeGreaterThan($job->timeout)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->tries)->toBe(1);
});

it('runs inside the tenant context of its organization', function () {
    $middleware = (new ExportContactsJob([1], $this->org->id, $this->user->id, 'xlsx', 'contacts.xlsx'))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithTenantContext::class);
});

it('exports tags and companies of the contacts without a query per contact', function () {
    $vip = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);
    $ids = [];

    foreach (range(1, 6) as $number) {
        $contact = ($this->contact)("Contact {$number}");
        $contact->tags()->sync([$vip->id]);
        $ids[] = $contact->id;
    }

    DB::enableQueryLog();
    ($this->exportNames)($ids, 'csv');
    $tagQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"contact_tag"'))->count();
    DB::disableQueryLog();

    expect($tagQueries)->toBe(1);
});
