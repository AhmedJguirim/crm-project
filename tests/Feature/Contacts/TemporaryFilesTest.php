<?php

use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\ProcessContactImportJob;
use App\Models\User;
use App\Support\TemporaryFile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->temporaryDirectory = sys_get_temp_dir().'/crm-test-tmp-'.Str::random(12);
    mkdir($this->temporaryDirectory);
    TemporaryFile::useDirectory($this->temporaryDirectory);

    $this->leftInTemporaryDirectory = fn (): array => glob($this->temporaryDirectory.'/*') ?: [];

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

afterEach(function () {
    TemporaryFile::useDirectory(null);

    array_map('unlink', glob($this->temporaryDirectory.'/*') ?: []);
    rmdir($this->temporaryDirectory);
});

it('reserves a single empty file with the extension in the directory set for the test', function () {
    $path = TemporaryFile::reserve('reserve-test-', 'csv');

    expect(($this->leftInTemporaryDirectory)())->toBe([$path])
        ->and($path)->toStartWith($this->temporaryDirectory.'/')
        ->and($path)->toEndWith('.csv')
        ->and(filesize($path))->toBe(0);
});

it('goes back to the system temp directory when no directory is set', function () {
    TemporaryFile::useDirectory(null);

    $path = TemporaryFile::reserve('reserve-test-', 'csv');

    expect(dirname($path))->toBe(rtrim(sys_get_temp_dir(), '/'));

    unlink($path);
});

it('leaves no temporary file behind when the import template is downloaded', function () {
    Livewire::test(ListContacts::class)
        ->callAction('downloadTemplate')
        ->assertFileDownloaded('contacts-import-template.xlsx');

    expect(($this->leftInTemporaryDirectory)())->toBe([]);
});

it('leaves no temporary file behind when an import has failed rows', function () {
    $path = 'contact-imports/test.csv';
    Storage::disk('local')->put($path, "name,email,phone,tags\nJane,jane@example.com,,\nBad,not-an-email,,\n");

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    expect(($this->leftInTemporaryDirectory)())->toBe([])
        ->and(collect(Storage::disk('local')->files('contact-imports'))->filter(fn (string $file): bool => str_contains($file, 'failed-')))->toHaveCount(1);
});

it('still writes the failed rows report with its columns', function () {
    $path = 'contact-imports/test.csv';
    Storage::disk('local')->put($path, "name,email,phone,tags\nBad,not-an-email,,\n");

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    $report = Storage::disk('local')->get(collect(Storage::disk('local')->files('contact-imports'))->first(fn (string $file): bool => str_contains($file, 'failed-')));
    $lines = explode("\n", trim(ltrim($report, "\xEF\xBB\xBF")));

    expect($lines[0])->toBe('_row_number,_error,name,email,phone,tags')
        ->and($lines[1])->toContain('Invalid email: not-an-email');
});
