<?php

use App\Jobs\ProcessContactImportJob;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');

    $this->ann = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->bob = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();

    Storage::disk('local')->put('contact-imports/ann.csv', "name,email,phone,tags\nBroken,not-an-email,,\n");
    ProcessContactImportJob::dispatchSync('contact-imports/ann.csv', $this->ann->personalOrganization()->id, $this->ann->id);

    $this->url = DatabaseNotification::where('notifiable_id', $this->ann->id)->sole()->data['actions'][0]['url'];
    $this->fileName = collect(Storage::disk('local')->files('contact-imports'))
        ->map(fn (string $file): string => basename($file))
        ->first(fn (string $file): bool => str_starts_with($file, 'failed-'));
});

it('lets the importer download her failed rows', function () {
    $response = $this->actingAs($this->ann)->get($this->url);

    $response->assertOk()
        ->assertDownload($this->fileName)
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($response->streamedContent())->toContain('Broken', 'not-an-email');
});

it('refuses the link to someone else', function () {
    $this->actingAs($this->bob)->get($this->url)->assertForbidden();
});

it('refuses a link whose user was changed', function () {
    $tampered = preg_replace('/user=\d+/', 'user='.$this->bob->id, $this->url);

    $this->actingAs($this->bob)->get($tampered)->assertForbidden();
});

it('refuses an unsigned link', function () {
    $this->actingAs($this->ann)
        ->get(route('contacts.import.failed-rows', ['file' => $this->fileName, 'user' => $this->ann->id]))
        ->assertForbidden();
});

it('refuses an expired link', function () {
    $this->travel(8)->days();

    $this->actingAs($this->ann)->get($this->url)->assertForbidden();
});

it('only accepts the file names of failed rows files', function (string $file) {
    Storage::disk('local')->put('livewire-tmp/abc.csv', 'secret');
    $url = URL::temporarySignedRoute('contacts.import.failed-rows', now()->addDay(), ['file' => $file, 'user' => $this->ann->id]);

    $this->actingAs($this->ann)->get($url)->assertNotFound();
})->with([
    'parent folder' => ['../livewire-tmp/abc.csv'],
    'traversal after the prefix' => ['failed-x/../../livewire-tmp/abc.csv'],
    'other name' => ['other.csv'],
    'short name' => ['failed-short.csv'],
]);

it('answers not found for a well-formed name that does not exist', function () {
    $url = URL::temporarySignedRoute('contacts.import.failed-rows', now()->addDay(), ['file' => 'failed-'.str_repeat('a', 40).'.csv', 'user' => $this->ann->id]);

    $this->actingAs($this->ann)->get($url)->assertNotFound();
});

it('sends guests to the login page', function () {
    $this->get($this->url)->assertRedirect(route('login'));
});

it('stores failed rows files under unguessable names', function () {
    expect($this->fileName)->toMatch('/^failed-[A-Za-z0-9]{40}\.csv$/');
});
