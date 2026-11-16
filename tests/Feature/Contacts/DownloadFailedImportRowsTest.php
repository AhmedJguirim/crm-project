<?php

use App\Enums\OrganizationRole;
use App\Jobs\ProcessCompanyImportJob;
use App\Jobs\ProcessContactImportJob;
use App\Models\Company;
use App\Models\Organization;
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
        ->get(route('contacts.import.failed-rows', ['file' => $this->fileName, 'organization' => $this->ann->personalOrganization()->id, 'user' => $this->ann->id]))
        ->assertForbidden();
});

it('refuses an expired link', function () {
    $this->travel(8)->days();

    $this->actingAs($this->ann)->get($this->url)->assertForbidden();
});

it('only accepts the file names of failed rows files', function (string $file) {
    Storage::disk('local')->put('livewire-tmp/abc.csv', 'secret');
    $url = URL::temporarySignedRoute('contacts.import.failed-rows', now()->addDay(), ['file' => $file, 'organization' => $this->ann->personalOrganization()->id, 'user' => $this->ann->id]);

    $this->actingAs($this->ann)->get($url)->assertNotFound();
})->with([
    'parent folder' => ['../livewire-tmp/abc.csv'],
    'traversal after the prefix' => ['failed-x/../../livewire-tmp/abc.csv'],
    'other name' => ['other.csv'],
    'short name' => ['failed-short.csv'],
    'old name without ids' => ['failed-'.str_repeat('a', 40).'.csv'],
    'trailing newline' => ['failed-o1-u1-'.str_repeat('a', 40).".csv\n"],
]);

it('answers not found for a well-formed name that does not exist', function () {
    $url = URL::temporarySignedRoute('contacts.import.failed-rows', now()->addDay(), ['file' => 'failed-o1-u1-'.str_repeat('a', 40).'.csv', 'organization' => $this->ann->personalOrganization()->id, 'user' => $this->ann->id]);

    $this->actingAs($this->ann)->get($url)->assertNotFound();
});

it('sends guests to the login page', function () {
    $this->get($this->url)->assertRedirect(route('login'));
});

it('stores failed rows files under unguessable names', function () {
    $organizationId = $this->ann->personalOrganization()->id;

    expect($this->fileName)->toMatch('/^failed-o'.$organizationId.'-u'.$this->ann->id.'-[A-Za-z0-9]{40}\.csv$/');
});

describe('the organization of the import', function () {
    beforeEach(function () {
        $this->acme = Organization::factory()->create(['name' => 'Acme']);
        $this->acme->members()->attach($this->ann->id, ['role' => OrganizationRole::Admin->value]);
        DatabaseNotification::query()->delete();
        Storage::disk('local')->deleteDirectory('contact-imports');

        $this->acmeContactImport = function (): string {
            Storage::disk('local')->put('contact-imports/acme.csv', "name,email,phone,tags\nBroken,not-an-email,,\n");
            ProcessContactImportJob::dispatchSync('contact-imports/acme.csv', $this->acme->id, $this->ann->id);

            return DatabaseNotification::where('notifiable_id', $this->ann->id)->latest('created_at')->firstOrFail()->data['actions'][0]['url'];
        };
        $this->open = fn (string $url) => $this->actingAs($this->ann->fresh())->get($url);
        $this->signed = fn (array $query): string => URL::temporarySignedRoute('contacts.import.failed-rows', now()->addDay(), ['user' => $this->ann->id, ...$query]);
    });

    it('puts the organization and both ids in the file name and the link', function () {
        $url = ($this->acmeContactImport)();

        expect($url)->toContain('organization='.$this->acme->id)
            ->and(Storage::disk('local')->files('contact-imports'))->toHaveCount(1)
            ->and(basename(Storage::disk('local')->files('contact-imports')[0]))->toMatch('/^failed-o'.$this->acme->id.'-u'.$this->ann->id.'-[A-Za-z0-9]{40}\.csv$/');

        $response = ($this->open)($url);

        $response->assertOk();
        expect($response->streamedContent())->toContain('Broken', 'not-an-email');
    });

    it('refuses a member who was removed from the organization after the import', function () {
        $url = ($this->acmeContactImport)();
        $file = Storage::disk('local')->files('contact-imports')[0];

        $this->acme->members()->detach($this->ann->id);

        ($this->open)($url)
            ->assertForbidden()
            ->assertSee('You can no longer import in this organization, so this file can&#039;t be downloaded.', false);
        expect(Storage::disk('local')->exists($file))->toBeTrue();
    });

    it('refuses a member who was demoted after the import', function (OrganizationRole $role) {
        $url = ($this->acmeContactImport)();

        ($this->open)($url)->assertOk();

        $this->acme->members()->updateExistingPivot($this->ann->id, ['role' => $role->value]);

        ($this->open)($url)
            ->assertForbidden()
            ->assertSee('You can no longer import in this organization, so this file can&#039;t be downloaded.', false);
    })->with([OrganizationRole::Member, OrganizationRole::Viewer]);

    it('refuses a link with a bad or missing organization without an error', function (?string $organization) {
        ($this->acmeContactImport)();
        $file = basename(Storage::disk('local')->files('contact-imports')[0]);

        $url = ($this->signed)(['file' => $file] + ($organization === null ? [] : ['organization' => $organization]));

        ($this->open)($url)
            ->assertForbidden()
            ->assertSee('You can no longer import in this organization', false);
    })->with(['letters' => 'abc', 'zero' => '0', 'negative' => '-1', 'huge' => '99999999999999999999999', 'padded' => ' 1', 'missing' => null]);

    it('refuses a link for an organization the user never belonged to', function () {
        ($this->acmeContactImport)();
        $file = basename(Storage::disk('local')->files('contact-imports')[0]);

        ($this->open)(($this->signed)(['file' => $file, 'organization' => Organization::factory()->create()->id]))->assertForbidden();
    });

    it('refuses to change the organization of a signed link', function () {
        $url = ($this->acmeContactImport)();
        $personal = $this->ann->personalOrganization()->id;

        $this->actingAs($this->ann)->get(preg_replace('/organization=\d+/', 'organization='.$personal, $url))->assertForbidden();
    });

    it('applies the same rule to the failed rows of a company import', function () {
        Company::factory()->create(['organization_id' => $this->acme->id, 'name' => 'Initech', 'website' => 'initech.com']);
        Storage::disk('local')->put('company-imports/acme.csv', "name,website\nInitech,initech.com\n");
        ProcessCompanyImportJob::dispatchSync('company-imports/acme.csv', $this->acme->id, $this->ann->id);
        $url = DatabaseNotification::where('notifiable_id', $this->ann->id)->latest('created_at')->firstOrFail()->data['actions'][0]['url'];

        ($this->open)($url)->assertOk();

        $this->acme->members()->updateExistingPivot($this->ann->id, ['role' => OrganizationRole::Member->value]);

        ($this->open)($url)->assertForbidden();
    });
});
