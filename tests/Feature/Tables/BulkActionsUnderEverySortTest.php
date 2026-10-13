<?php

use App\Enums\ExportFormat;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\CompanyCustomFields\Pages\ListCompanyCustomFields;
use App\Filament\Resources\CompanyTypes\Pages\ManageCompanyTypes;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Jobs\ExportCompaniesJob;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\User;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\Column;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create();
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $makeCompany = fn (string $name): Company => Company::factory()->create([
        'organization_id' => $this->org->id,
        'name' => $name,
        'custom_field_values' => [],
    ]);

    $this->ann = $makeCompany('Ann Ltd');
    $this->bob = $makeCompany('Bob Inc');
    $this->cid = $makeCompany('Cid SA');

    $this->ann->contacts()->attach(Contact::factory()->count(2)->create(['organization_id' => $this->org->id])->modelKeys());
    $this->cid->contacts()->attach(Contact::factory()->create(['organization_id' => $this->org->id])->id);
    $trashedContact = Contact::factory()->create(['organization_id' => $this->org->id]);
    $this->cid->contacts()->attach($trashedContact->id);
    $trashedContact->delete();
});

test('the companies list shows and sorts the live contact counts', function () {
    Livewire::test(ListCompanies::class)
        ->sortTable('contacts_count', 'desc')
        ->assertCanSeeTableRecords([$this->ann, $this->cid, $this->bob], inOrder: true)
        ->assertTableColumnStateSet('contacts_count', 2, $this->ann)
        ->assertTableColumnStateSet('contacts_count', 1, $this->cid)
        ->assertTableColumnStateSet('contacts_count', 0, $this->bob)
        ->sortTable('contacts_count', 'asc')
        ->assertCanSeeTableRecords([$this->bob, $this->cid, $this->ann], inOrder: true);
});

test('export selected works under the contacts sort', function (string $direction) {
    Queue::fake();

    Livewire::test(ListCompanies::class)
        ->sortTable('contacts_count', $direction)
        ->callTableBulkAction('exportSelected', [$this->ann, $this->cid], ['format' => ExportFormat::Csv->value])
        ->assertNotified('Export queued');

    $jobs = Queue::pushed(ExportCompaniesJob::class);

    expect($jobs)->toHaveCount(1);

    $job = $jobs->first();

    expect((fn (): array => $this->companyIds)->call($job))->toEqualCanonicalizing([$this->ann->id, $this->cid->id])
        ->and((fn () => $this->format)->call($job))->toBe(ExportFormat::Csv->value);
})->with(['asc', 'desc']);

test('delete selected and restore selected work under the contacts sort', function (string $direction) {
    Livewire::test(ListCompanies::class)
        ->sortTable('contacts_count', $direction)
        ->selectTableRecords([$this->ann, $this->bob])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    $this->assertSoftDeleted($this->ann);
    $this->assertSoftDeleted($this->bob);
    $this->assertNotSoftDeleted($this->cid);

    Livewire::test(ListCompanies::class)
        ->filterTable('trashed', false)
        ->sortTable('contacts_count', $direction)
        ->selectTableRecords([$this->ann])
        ->callAction(TestAction::make(RestoreBulkAction::class)->table()->bulk());

    $this->assertNotSoftDeleted($this->ann);
    $this->assertSoftDeleted($this->bob);
})->with(['asc', 'desc']);

test('company types delete and restore selected work under the companies sort', function (string $direction) {
    $agency = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Agency']);
    $startup = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Startup']);
    $empty = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'Empty']);
    $this->ann->update(['company_type_id' => $agency->id]);
    $this->bob->update(['company_type_id' => $startup->id]);

    Livewire::test(ManageCompanyTypes::class)
        ->sortTable('companies_count', $direction)
        ->assertTableColumnStateSet('companies_count', 0, $empty)
        ->assertTableColumnStateSet('companies_count', 1, $agency)
        ->selectTableRecords([$empty])
        ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk());

    $this->assertSoftDeleted($empty);
    $this->assertNotSoftDeleted($agency);

    Livewire::test(ManageCompanyTypes::class)
        ->filterTable('trashed', false)
        ->sortTable('companies_count', $direction)
        ->selectTableRecords([$empty])
        ->callAction(TestAction::make(RestoreBulkAction::class)->table()->bulk());

    $this->assertNotSoftDeleted($empty);
})->with(['asc', 'desc']);

test('the selected records query runs under every sortable column of every list', function (string $pageClass) {
    $page = Livewire::test($pageClass);

    $columns = collect($page->instance()->getTable()->getVisibleColumns())
        ->filter(fn (Column $column): bool => $column->isSortable())
        ->keys();

    foreach ($columns as $name) {
        foreach (['asc', 'desc'] as $direction) {
            $page->call('sortTable', $name, $direction)
                ->set('isTrackingDeselectedTableRecords', false)
                ->set('selectedTableRecords', ['1']);

            expect(fn () => $page->instance()->getSelectedTableRecordsQuery()->get())
                ->not->toThrow(QueryException::class);
        }
    }

    expect(true)->toBeTrue();
})->with([
    ListCompanies::class,
    ManageCompanyTypes::class,
    ListContacts::class,
    ListDeals::class,
    ListTasks::class,
    ListInvoices::class,
    ListSegments::class,
    ListTags::class,
    ListCustomFields::class,
    ListCompanyCustomFields::class,
]);
