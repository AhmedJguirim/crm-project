<?php

use Illuminate\Support\Facades\Storage;

const UPLOAD_NAME = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789AbCd';

beforeEach(function () {
    $this->freezeSecond();

    Storage::fake('local');
    Storage::fake('tmp-for-tests');

    $this->uploadModifiedAgo = function (string $diskName, string $file, int $seconds): void {
        $disk = Storage::disk($diskName);
        $disk->put($file, 'x');
        touch($disk->path($file), now()->subSeconds($seconds)->getTimestamp());
    };
});

it('deletes old temporary uploads and keeps recent ones', function () {
    ($this->uploadModifiedAgo)('tmp-for-tests', 'livewire-tmp/old.csv', 25 * 3600);
    ($this->uploadModifiedAgo)('tmp-for-tests', 'livewire-tmp/old.csv.json', 25 * 3600);
    ($this->uploadModifiedAgo)('tmp-for-tests', 'livewire-tmp/recent.csv', 3600);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 2 upload files.')->assertSuccessful();

    expect(Storage::disk('tmp-for-tests')->allFiles('livewire-tmp'))->toBe(['livewire-tmp/recent.csv']);
});

it('prunes temporary uploads in subfolders too', function () {
    ($this->uploadModifiedAgo)('tmp-for-tests', 'livewire-tmp/nested/old.csv', 48 * 3600);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 1 upload file.')->assertSuccessful();

    expect(Storage::disk('tmp-for-tests')->exists('livewire-tmp/nested/old.csv'))->toBeFalse();
});

it('respects the 24-hour boundary', function (string $diskName, string $file, int $seconds, bool $exists) {
    ($this->uploadModifiedAgo)($diskName, $file, $seconds);

    $this->artisan('uploads:prune')->assertSuccessful();

    expect(Storage::disk($diskName)->exists($file))->toBe($exists);
})->with([
    'livewire-tmp exactly 24 hours' => ['tmp-for-tests', 'livewire-tmp/edge.csv', 24 * 3600, true],
    'livewire-tmp 24 hours 1 second' => ['tmp-for-tests', 'livewire-tmp/edge.csv', 24 * 3600 + 1, false],
    'contact-imports exactly 24 hours' => ['local', 'contact-imports/'.UPLOAD_NAME.'.csv', 24 * 3600, true],
    'contact-imports 24 hours 1 second' => ['local', 'contact-imports/'.UPLOAD_NAME.'.csv', 24 * 3600 + 1, false],
    'company-imports exactly 24 hours' => ['local', 'company-imports/'.UPLOAD_NAME.'.xlsx', 24 * 3600, true],
    'company-imports 24 hours 1 second' => ['local', 'company-imports/'.UPLOAD_NAME.'.xlsx', 24 * 3600 + 1, false],
]);

it('deletes an abandoned import upload and keeps a recent one waiting in the queue', function () {
    ($this->uploadModifiedAgo)('local', 'contact-imports/'.UPLOAD_NAME.'.csv', 3 * 86400);
    ($this->uploadModifiedAgo)('local', 'company-imports/ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210ZyXw.csv', 3 * 3600);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 1 upload file.')->assertSuccessful();

    expect(Storage::disk('local')->exists('contact-imports/'.UPLOAD_NAME.'.csv'))->toBeFalse()
        ->and(Storage::disk('local')->exists('company-imports/ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210ZyXw.csv'))->toBeTrue();
});

it('respects the 7-day boundary for failed-rows files', function (string $directory, int $seconds, bool $exists) {
    $file = "{$directory}/failed-".UPLOAD_NAME.'.csv';
    ($this->uploadModifiedAgo)('local', $file, $seconds);

    $this->artisan('uploads:prune')->assertSuccessful();

    expect(Storage::disk('local')->exists($file))->toBe($exists);
})->with([
    'contact-imports 6 days' => ['contact-imports', 6 * 86400, true],
    'contact-imports exactly 7 days' => ['contact-imports', 7 * 86400, true],
    'contact-imports 7 days 1 second' => ['contact-imports', 7 * 86400 + 1, false],
    'company-imports 7 days 1 second' => ['company-imports', 7 * 86400 + 1, false],
]);

it('respects the 7-day boundary for failed-rows files named with their organization and user', function (int $seconds, bool $exists) {
    $file = 'contact-imports/failed-o3-u7-'.UPLOAD_NAME.'.csv';
    ($this->uploadModifiedAgo)('local', $file, $seconds);

    $this->artisan('uploads:prune')->assertSuccessful();

    expect(Storage::disk('local')->exists($file))->toBe($exists);
})->with([
    '2 days' => [2 * 86400, true],
    'exactly 7 days' => [7 * 86400, true],
    '7 days 1 second' => [7 * 86400 + 1, false],
]);

it('counts an old failed-rows file and an abandoned upload together', function () {
    ($this->uploadModifiedAgo)('local', 'contact-imports/failed-'.UPLOAD_NAME.'.csv', 30 * 86400);
    ($this->uploadModifiedAgo)('local', 'contact-imports/ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210ZyXw.csv', 3 * 86400);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 2 upload files.')->assertSuccessful();

    expect(Storage::disk('local')->allFiles('contact-imports'))->toBe([]);
});

it('keeps a 2-day-old failed-rows file while a 2-day-old upload is deleted', function () {
    ($this->uploadModifiedAgo)('local', 'contact-imports/failed-'.UPLOAD_NAME.'.csv', 2 * 86400);
    ($this->uploadModifiedAgo)('local', 'contact-imports/ZyXwVuTsRqPoNmLkJiHgFeDcBa9876543210ZyXw.csv', 2 * 86400);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 1 upload file.')->assertSuccessful();

    expect(Storage::disk('local')->allFiles('contact-imports'))->toBe(['contact-imports/failed-'.UPLOAD_NAME.'.csv']);
});

it('prunes a file that only looks like a failed-rows file', function (string $name) {
    ($this->uploadModifiedAgo)('local', "contact-imports/{$name}", 30 * 86400);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 1 upload file.')->assertSuccessful();

    expect(Storage::disk('local')->exists("contact-imports/{$name}"))->toBeFalse();
})->with([
    'too short' => 'failed-short.csv',
    'one character too long' => 'failed-'.UPLOAD_NAME.'x.csv',
    'wrong extension' => 'failed-'.UPLOAD_NAME.'.xlsx',
    'truncated extension' => 'failed-'.UPLOAD_NAME.'.cs',
    'suffix after the extension' => 'failed-'.UPLOAD_NAME.'.csv.bak',
    'prefix before failed' => 'old-failed-'.UPLOAD_NAME.'.csv',
    'organization id zero' => 'failed-o0-u7-'.UPLOAD_NAME.'.csv',
    'missing user part' => 'failed-o3-'.UPLOAD_NAME.'.csv',
]);

it('prunes a failed-rows looking name in the temporary folder, where the guard does not apply', function () {
    ($this->uploadModifiedAgo)('tmp-for-tests', 'livewire-tmp/failed-'.UPLOAD_NAME.'.csv', 30 * 86400);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 1 upload file.')->assertSuccessful();
});

it('leaves other folders and nested import folders alone', function () {
    ($this->uploadModifiedAgo)('local', 'exports/contacts-old.xlsx', 30 * 86400);
    ($this->uploadModifiedAgo)('local', 'organization-logos/logo.png', 30 * 86400);
    ($this->uploadModifiedAgo)('local', 'contact-imports/sub/deep.csv', 30 * 86400);
    ($this->uploadModifiedAgo)('local', 'livewire-tmp/not-on-local-disk.csv', 30 * 86400);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 0 upload files.')->assertSuccessful();

    expect(Storage::disk('local')->exists('exports/contacts-old.xlsx'))->toBeTrue()
        ->and(Storage::disk('local')->exists('organization-logos/logo.png'))->toBeTrue()
        ->and(Storage::disk('local')->exists('contact-imports/sub/deep.csv'))->toBeTrue()
        ->and(Storage::disk('local')->exists('livewire-tmp/not-on-local-disk.csv'))->toBeTrue();
});

it('works when no upload folder exists yet', function () {
    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 0 upload files.')->assertSuccessful();
});

it('is idempotent: a second run deletes nothing', function () {
    ($this->uploadModifiedAgo)('local', 'contact-imports/'.UPLOAD_NAME.'.csv', 3 * 86400);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 1 upload file.');
    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 0 upload files.')->assertSuccessful();
});

it('skips a file that disappears between the listing and the age check', function () {
    ($this->uploadModifiedAgo)('local', 'contact-imports/'.UPLOAD_NAME.'.csv', 3 * 86400);

    $local = Mockery::mock(Storage::disk('local'))->makePartial();
    $local->shouldReceive('files')->with('contact-imports')->andReturn(['contact-imports/gone.csv']);
    $local->shouldReceive('files')->with('company-imports')->andReturn([]);
    Storage::set('local', $local);

    $this->artisan('uploads:prune')->expectsOutputToContain('Deleted 0 upload files.')->assertSuccessful();

    expect(Storage::disk('local')->exists('contact-imports/'.UPLOAD_NAME.'.csv'))->toBeTrue();
});
