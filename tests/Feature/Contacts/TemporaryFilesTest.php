<?php

use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Jobs\ProcessContactImportJob;
use App\Models\User;
use App\Support\TemporaryFile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->user->personalOrganization();
    $this->actingAs($this->user);
    Filament::setTenant($this->org);
});

/** @return array<int, string> */
function temporaryFilesStartingWith(string $prefix): array
{
    return glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.'*') ?: [];
}

it('reserves a single empty file with the extension', function () {
    $before = temporaryFilesStartingWith('reserve-test-');

    $path = TemporaryFile::reserve('reserve-test-', 'csv');
    $created = array_values(array_diff(temporaryFilesStartingWith('reserve-test-'), $before));

    expect($created)->toBe([$path])
        ->and($path)->toEndWith('.csv')
        ->and(filesize($path))->toBe(0);

    unlink($path);
});

it('leaves no temporary file behind when the import template is downloaded', function () {
    $before = temporaryFilesStartingWith('contacts-template-');

    Livewire::test(ListContacts::class)
        ->callAction('downloadTemplate')
        ->assertFileDownloaded('contacts-import-template.xlsx');

    expect(array_values(array_diff(temporaryFilesStartingWith('contacts-template-'), $before)))->toBe([]);
});

it('leaves no temporary file behind when an import has failed rows', function () {
    $before = [...temporaryFilesStartingWith('failed-rows-'), ...temporaryFilesStartingWith('contact-import-')];
    $path = 'contact-imports/test.csv';
    Storage::disk('local')->put($path, "name,email,phone,tags\nJane,jane@example.com,,\nBad,not-an-email,,\n");

    ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->user->id);

    $after = [...temporaryFilesStartingWith('failed-rows-'), ...temporaryFilesStartingWith('contact-import-')];

    expect(array_values(array_diff($after, $before)))->toBe([])
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
