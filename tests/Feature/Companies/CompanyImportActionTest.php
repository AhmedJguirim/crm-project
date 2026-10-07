<?php

use App\Enums\OrganizationRole;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\CompanyCustomFields\Pages\CreateCompanyCustomField;
use App\Filament\Resources\CompanyCustomFields\Pages\EditCompanyCustomField;
use App\Jobs\ProcessCompanyImportJob;
use App\Models\CompanyCustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();

    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();

    $this->actAs = function (OrganizationRole $role): User {
        $user = User::factory()->onboardingCompleted()->create();
        $this->org->members()->attach($user, ['role' => $role->value]);
        $this->actingAs($user);
        Filament::setTenant($this->org);

        return $user;
    };
});

it('queues the import of the file for an admin and the owner', function (OrganizationRole $role) {
    $user = ($this->actAs)($role);

    Livewire::test(ListCompanies::class)
        ->assertActionVisible('importCompanies')
        ->callAction('importCompanies', ['file' => UploadedFile::fake()->create('c.csv', 10, 'text/csv')])
        ->assertNotified('Import queued');

    Queue::assertPushed(ProcessCompanyImportJob::class, function (ProcessCompanyImportJob $job) use ($user): bool {
        $properties = (fn (): array => [$this->filePath, $this->organizationId, $this->userId])->call($job);

        return str_starts_with($properties[0], 'company-imports/') && $properties[1] === $this->org->id && $properties[2] === $user->id;
    });
})->with([OrganizationRole::Admin, OrganizationRole::Owner]);

it('hides the import from a member and a viewer, who can still download the template', function (OrganizationRole $role) {
    ($this->actAs)($role);

    Livewire::test(ListCompanies::class)
        ->assertActionHidden('importCompanies')
        ->assertActionVisible('downloadTemplate');
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

it('refuses the import to a member or a viewer who calls the action anyway', function (OrganizationRole $role) {
    ($this->actAs)($role);

    Livewire::test(ListCompanies::class)
        ->call('mountAction', 'importCompanies', [], [])
        ->assertActionNotMounted()
        ->set('mountedActions.0.data.file', UploadedFile::fake()->create('c.csv', 10, 'text/csv'))
        ->call('callMountedAction');

    Queue::assertNotPushed(ProcessCompanyImportJob::class);
})->with([OrganizationRole::Member, OrganizationRole::Viewer]);

it('refuses a file that is too large, and one of an unsupported type', function () {
    ($this->actAs)(OrganizationRole::Admin);

    Livewire::test(ListCompanies::class)
        ->callAction('importCompanies', ['file' => UploadedFile::fake()->create('big.csv', 11 * 1024, 'text/csv')])
        ->assertHasActionErrors(['file']);

    Livewire::test(ListCompanies::class)
        ->callAction('importCompanies', ['file' => UploadedFile::fake()->create('c.pdf', 10, 'application/pdf')])
        ->assertHasActionErrors(['file']);

    Queue::assertNotPushed(ProcessCompanyImportJob::class);
});

it('explains the separator, the date formats and that existing companies are kept', function () {
    ($this->actAs)(OrganizationRole::Admin);

    Livewire::test(ListCompanies::class)
        ->mountAction('importCompanies')
        ->assertSchemaComponentExists('file', 'mountedActionSchema0', function (FileUpload $field): bool {
            $helperText = collect($field->getChildComponents($field::BELOW_CONTENT_SCHEMA_KEY))->map(fn ($component): string => (string) $component->getContent())->implode(' ');

            return str_contains($helperText, 'dd-mm-yyyy')
                && str_contains($helperText, 'Separate several multi-select values with ";"')
                && str_contains($helperText, 'already exists is not updated');
        });
});

describe('reserved company field names', function () {
    beforeEach(function () {
        ($this->actAs)(OrganizationRole::Admin);
    });

    it('are refused on creation', function (string $name) {
        $page = Livewire::test(CreateCompanyCustomField::class)
            ->fillForm(['name' => $name, 'type' => 'text'])
            ->call('create')
            ->assertHasFormErrors(['name']);

        expect($page->errors()->get('data.name'))->toBe(['This name is used by the import. Choose another name.'])
            ->and(CompanyCustomField::forOrganization($this->org->id)->count())->toBe(0);
    })->with([' Website ', 'NAME', 'Annual Revenue', 'zip', 'Notes', '_row_number', '_ERROR', 'type']);

    it('are refused when renaming a field', function () {
        $field = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Region', 'type' => 'text']);

        Livewire::test(EditCompanyCustomField::class, ['record' => $field->getRouteKey()])
            ->fillForm(['name' => 'Phone'])
            ->call('save')
            ->assertHasFormErrors(['name']);

        expect($field->fresh()->name)->toBe('Region');
    });

    it('do not refuse a name that only contains a reserved word, or a saved field kept as is', function () {
        Livewire::test(CreateCompanyCustomField::class)
            ->fillForm(['name' => 'Company size', 'type' => 'text'])
            ->call('create')
            ->assertHasNoFormErrors();

        $field = CompanyCustomField::forOrganization($this->org->id)->sole();

        Livewire::test(EditCompanyCustomField::class, ['record' => $field->getRouteKey()])
            ->fillForm(['name' => 'Company size'])
            ->call('save')
            ->assertHasNoFormErrors();
    });
});
