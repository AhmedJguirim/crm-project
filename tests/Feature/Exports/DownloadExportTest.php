<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');

    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    $this->stored = function (string $extension = 'xlsx', string $contents = 'file contents'): string {
        $file = 'contacts-'.Str::random(40).".{$extension}";
        Storage::disk('local')->put("exports/{$file}", $contents);

        return $file;
    };

    $this->link = fn (array $query, ?int $userId = null): string => URL::temporarySignedRoute(
        'exports.download',
        now()->addDays(7),
        ['user' => $userId ?? $this->ann->id, ...$query],
    );
});

it('lets the user download the file under the name given in the link', function () {
    $file = ($this->stored)('xlsx', 'xlsx bytes');

    $response = $this->actingAs($this->ann)->get(($this->link)(['file' => $file, 'name' => 'contacts-2026-10-06.xlsx']));

    $response->assertOk()
        ->assertDownload('contacts-2026-10-06.xlsx')
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($response->streamedContent())->toBe('xlsx bytes');
});

it('serves a csv as text/csv', function () {
    $file = ($this->stored)('csv');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file, 'name' => 'contacts.csv']))
        ->assertOk()
        ->assertDownload('contacts.csv')
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
});

it('uses the stored file name when the name in the link is not a plain file name', function (string $name) {
    $file = ($this->stored)('csv');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file, 'name' => $name]))
        ->assertOk()
        ->assertDownload($file);
})->with(['a path' => '../../.env', 'a space' => 'my contacts.csv', 'too long' => str_repeat('a', 101).'.csv', 'empty' => '']);

it('uses the stored file name when the link has no name', function () {
    $file = ($this->stored)('csv');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file]))->assertDownload($file);
});

it('refuses the link to someone else, or one whose user was changed', function () {
    $file = ($this->stored)();
    $url = ($this->link)(['file' => $file]);

    $this->actingAs($this->bob)->get($url)->assertForbidden();
    $this->actingAs($this->bob)->get(preg_replace('/user=\d+/', 'user='.$this->bob->id, $url))->assertForbidden();
});

it('refuses an unsigned or expired link', function () {
    $file = ($this->stored)();

    $this->actingAs($this->ann)->get(route('exports.download', ['file' => $file, 'user' => $this->ann->id]))->assertForbidden();

    $url = ($this->link)(['file' => $file]);
    $this->travel(8)->days();

    $this->actingAs($this->ann)->get($url)->assertForbidden();
});

it('sends a guest to the login page', function () {
    $file = ($this->stored)();

    $this->get(($this->link)(['file' => $file]))->assertRedirect();
});

it('only accepts the file names of exports', function (string $file) {
    Storage::disk('local')->put('livewire-tmp/secret.csv', 'secret');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file]))->assertNotFound();
})->with([
    'a path' => '../.env',
    'a path to another folder' => '../livewire-tmp/secret.csv',
    'too short' => 'contacts-short.xlsx',
    'wrong extension' => 'contacts-'.str_repeat('a', 40).'.pdf',
    'wrong prefix' => 'companies-'.str_repeat('a', 40).'.xlsx',
    'a failed rows file' => 'failed-'.str_repeat('a', 40).'.csv',
    'a trailing newline' => 'contacts-'.str_repeat('a', 40).".csv\n",
]);

it('refuses an existing file whose name is not the one of an export', function (string $file) {
    Storage::disk('local')->put("exports/{$file}", 'secret');

    $this->actingAs($this->ann)->get(($this->link)(['file' => $file]))->assertNotFound();
})->with(['too short' => 'contacts-short.xlsx', 'too long' => 'contacts-'.str_repeat('a', 41).'.csv', 'other characters' => 'contacts-'.str_repeat('a', 39).'_.csv']);

it('answers 404 when the file is gone', function () {
    $this->actingAs($this->ann)
        ->get(($this->link)(['file' => 'contacts-'.str_repeat('a', 40).'.xlsx']))
        ->assertNotFound();
});

it('needs the file parameter', function () {
    $this->actingAs($this->ann)->get(($this->link)([]))->assertNotFound();
});
