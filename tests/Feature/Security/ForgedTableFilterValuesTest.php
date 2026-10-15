<?php

use App\Enums\InvoiceStatus;
use App\Filament\Pages\Tenancy\OrganizationMembers;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\RelationManagers\ContactsRelationManager as CompanyContactsRelationManager;
use App\Filament\Resources\Companies\RelationManagers\DealsRelationManager;
use App\Filament\Resources\CompanyCustomFields\CompanyCustomFieldResource;
use App\Filament\Resources\CompanyCustomFields\Pages\ListCompanyCustomFields;
use App\Filament\Resources\CompanyTypes\CompanyTypeResource;
use App\Filament\Resources\CompanyTypes\Pages\ManageCompanyTypes;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Segments\RelationManagers\ContactsRelationManager as SegmentContactsRelationManager;
use App\Filament\Resources\Segments\SegmentResource;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tags\TagResource;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Resources\Tasks\TaskResource;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\Filters\RecordKeySelectFilter;
use App\Livewire\Tenancy\InvitationsList;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Test Owner']);
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee', 'email' => 'ann.lee@example.test']);
    $this->bob = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob Ray', 'email' => 'bob.ray@example.test']);

    $this->annDeal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->ann->id,
        'owner_id' => $this->owner->id,
        'created_by' => $this->owner->id,
        'title' => 'Ann deal',
    ]);
    $this->bobDeal = Deal::factory()->inStage('Discovery')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->bob->id,
        'owner_id' => null,
        'created_by' => $this->owner->id,
        'title' => 'Bob deal',
    ]);

    $this->gina = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Gina Globex']);
    $this->globexContact = Contact::factory()->create([
        'organization_id' => $this->gina->personalOrganization()->id,
        'name' => 'Globex Secret Contact',
    ]);
});

/**
 * @return array<string, array<int, mixed>> junk values by shape
 */
function forgedFilterJunk(bool $multiple): array
{
    if ($multiple) {
        return [
            'scalar' => ['abc'],
            'list of junk' => [['abc']],
            'list of unknown options' => [['bogus']],
            'nested list' => [[['1']]],
            'negative' => [['-1']],
            'too big' => [['9223372036854775808']],
        ];
    }

    return [
        'text' => ['abc'],
        'unknown option' => ['bogus'],
        'array' => [['abc']],
        'negative' => ['-1'],
        'zero' => ['0'],
        'decimal' => ['1.5'],
        'padded' => [' 1'],
        'too big' => ['9223372036854775808'],
    ];
}

test('every select filter of every list is a guarded filter and ignores junk', function (string $page, bool $hasSelectFilters) {
    $component = Livewire::test($page)->assertSuccessful();
    $filters = $component->instance()->getTable()->getFilters();
    $checked = 0;

    foreach ($filters as $name => $filter) {
        if (! $filter instanceof SelectFilter || $filter instanceof TernaryFilter) {
            continue;
        }

        expect($filter instanceof RecordKeySelectFilter || $filter instanceof OptionSelectFilter)->toBeTrue("{$page}: {$name} must be a guarded select filter");

        $path = "tableFilters.{$name}.".($filter->isMultiple() ? 'values' : 'value');

        $component->set("tableFilters.{$name}", null);
        $activeWithoutIt = $component->instance()->getTable()->getActiveFiltersCount();

        foreach (forgedFilterJunk($filter->isMultiple()) as $case => [$junk]) {
            expect(fn () => $component->set($path, $junk))->not->toThrow(Throwable::class, "{$name}: {$case}");
            expect($component->instance()->getTable()->getActiveFiltersCount())->toBe($activeWithoutIt, "{$name}: {$case} must not count as active");
            $checked++;
        }

        expect(fn () => $component->set("tableFilters.{$name}", 'abc'))->not->toThrow(Throwable::class, "{$name}: whole filter as text");

        $component->set("tableFilters.{$name}", null);
    }

    expect($checked > 0)->toBe($hasSelectFilters);
})->with([
    'ListDeals' => [ListDeals::class, true],
    'DealPipeline' => [DealPipeline::class, true],
    'ListInvoices' => [ListInvoices::class, true],
    'ListTasks' => [ListTasks::class, true],
    'ListContacts' => [ListContacts::class, true],
    'ListCompanies' => [ListCompanies::class, true],
    'ListSegments' => [ListSegments::class, false],
    'ListTags' => [ListTags::class, false],
    'ListCustomFields' => [ListCustomFields::class, false],
    'ListCompanyCustomFields' => [ListCompanyCustomFields::class, false],
    'ManageCompanyTypes' => [ManageCompanyTypes::class, false],
]);

test('a junk contact id is ignored on the deals list', function () {
    Livewire::test(ListDeals::class)
        ->set('tableFilters.contact_id.value', 'abc')
        ->assertCanSeeTableRecords([$this->annDeal, $this->bobDeal])
        ->assertDontSee('Contact: ');
});

test('a valid contact id still filters', function () {
    Livewire::test(ListDeals::class)
        ->set('tableFilters.contact_id.value', (string) $this->ann->id)
        ->assertCanSeeTableRecords([$this->annDeal])
        ->assertCanNotSeeTableRecords([$this->bobDeal])
        ->assertSee('Contact: Ann Lee');
});

test('another organization contact matches nothing and its name never shows', function () {
    Livewire::test(ListDeals::class)
        ->set('tableFilters.contact_id.value', (string) $this->globexContact->id)
        ->assertCanNotSeeTableRecords([$this->annDeal, $this->bobDeal])
        ->assertDontSee('Globex Secret Contact');
});

test('another organization user in the owner filter matches nothing', function () {
    Livewire::test(ListDeals::class)
        ->set('tableFilters.owner_id.value', (string) $this->gina->id)
        ->assertCanNotSeeTableRecords([$this->annDeal, $this->bobDeal])
        ->assertDontSee('Gina Globex');
});

test('junk items in a multiple id filter are dropped and valid ones kept', function () {
    $vip = Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);
    $this->ann->tags()->sync([$vip->id]);

    Livewire::test(ListContacts::class)
        ->set('tableFilters.tags.values', ['abc', (string) $vip->id])
        ->assertCanSeeTableRecords([$this->ann])
        ->assertCanNotSeeTableRecords([$this->bob]);
});

test('an unknown stage id is dropped and a known one kept', function () {
    $lead = pipelineStageNamed($this->org->id, 'Lead');

    Livewire::test(ListDeals::class)
        ->set('tableFilters.pipeline_stage_id.values', [(string) $lead->id, 'bogus'])
        ->assertCanSeeTableRecords([$this->annDeal])
        ->assertCanNotSeeTableRecords([$this->bobDeal])
        ->set('tableFilters.pipeline_stage_id.values', (string) $lead->id)
        ->assertCanSeeTableRecords([$this->annDeal, $this->bobDeal]);
});

test('the stage id of another organization in the deals filter matches nothing', function () {
    $foreign = pipelineStageNamed($this->gina->personalOrganization()->id, 'Lead');

    Livewire::test(ListDeals::class)
        ->set('tableFilters.pipeline_stage_id.values', [(string) $foreign->id])
        ->assertCanNotSeeTableRecords([$this->annDeal, $this->bobDeal]);

    $this->get(DealResource::getUrl('index', tenant: $this->org).'?filters[pipeline_stage_id][values][0]='.$foreign->id)->assertOk();
});

test('the board ignores a junk contact id', function () {
    Livewire::test(DealPipeline::class)
        ->set('tableFilters.contact_id.value', 'abc')
        ->assertSee('Ann deal')
        ->assertSee('Bob deal');
});

test('the invoice date range ignores a non-date', function () {
    $january = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->ann->id,
        'status' => InvoiceStatus::Sent,
        'issued_at' => '2026-01-10',
    ]);
    $march = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->ann->id,
        'status' => InvoiceStatus::Sent,
        'issued_at' => '2026-03-10',
    ]);

    Livewire::test(ListInvoices::class)
        ->set('tableFilters.issued_at_range.from', 'abc')
        ->set('tableFilters.issued_at_range.until', '2026-02-30')
        ->assertCanSeeTableRecords([$january, $march])
        ->set('tableFilters.issued_at_range.from', '2026-03-01')
        ->set('tableFilters.issued_at_range.until', null)
        ->assertCanSeeTableRecords([$march])
        ->assertCanNotSeeTableRecords([$january]);
});

dataset('forgedFilterPages', [
    'ListDeals' => [ListDeals::class],
    'DealPipeline' => [DealPipeline::class],
    'ListInvoices' => [ListInvoices::class],
    'ListTasks' => [ListTasks::class],
    'ListContacts' => [ListContacts::class],
    'ListCompanies' => [ListCompanies::class],
    'ListSegments' => [ListSegments::class],
    'ListTags' => [ListTags::class],
    'ListCustomFields' => [ListCustomFields::class],
    'ListCompanyCustomFields' => [ListCompanyCustomFields::class],
    'ManageCompanyTypes' => [ManageCompanyTypes::class],
]);

test('the invoices list with an array date in the URL is a 200', function (string $query) {
    $this->get(InvoiceResource::getUrl('index', tenant: $this->org).'?'.$query)->assertOk();
})->with([
    'array from' => ['filters[issued_at_range][from][]=abc'],
    'map until' => ['filters[issued_at_range][until][a]=1'],
    'nested from' => ['filters[issued_at_range][from][a][b]=1'],
]);

test('every filter field of every list survives array junk from the URL and from an update', function (string $page) {
    $filters = Livewire::test($page)->assertSuccessful()->instance()->getTable()->getFilters();
    $checked = 0;

    foreach ($filters as $name => $filter) {
        foreach ($filter->getSchemaComponents() as $field) {
            if (! $field instanceof Field) {
                continue;
            }

            $fieldName = $field->getName();

            foreach ([['abc'], ['a' => '1'], [['x']]] as $junk) {
                $booted = Livewire::withQueryParams(['filters' => [$name => [$fieldName => $junk]]])->test($page)->assertSuccessful();

                if ($field instanceof DateTimePicker) {
                    expect($booted->get("tableFilters.{$name}.{$fieldName}"))->toBeNull("{$page}: {$name}.{$fieldName} from the URL");
                }

                $component = Livewire::test($page);
                expect(fn () => $component->set("tableFilters.{$name}.{$fieldName}", $junk))->not->toThrow(Throwable::class, "{$page}: {$name}.{$fieldName} from an update");
                $component->assertSuccessful();
                $checked++;
            }
        }

        foreach (['abc', ['abc']] as $whole) {
            Livewire::withQueryParams(['filters' => [$name => $whole]])->test($page)->assertSuccessful();
        }
    }

    expect($checked)->toBeGreaterThan(0);
})->with('forgedFilterPages');

test('a valid date from the URL still filters', function () {
    $january = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->ann->id,
        'status' => InvoiceStatus::Sent,
        'issued_at' => '2026-01-10',
    ]);
    $march = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->ann->id,
        'status' => InvoiceStatus::Sent,
        'issued_at' => '2026-03-10',
    ]);

    Livewire::withQueryParams(['filters' => ['issued_at_range' => ['from' => '2026-03-01']]])
        ->test(ListInvoices::class)
        ->assertCanSeeTableRecords([$march])
        ->assertCanNotSeeTableRecords([$january]);
});

dataset('forgedSearchUrls', [
    'contacts' => [ContactResource::class, 'index'],
    'companies' => [CompanyResource::class, 'index'],
    'deals' => [DealResource::class, 'index'],
    'pipeline' => [DealResource::class, 'pipeline'],
    'invoices' => [InvoiceResource::class, 'index'],
    'tasks' => [TaskResource::class, 'index'],
    'segments' => [SegmentResource::class, 'index'],
    'tags' => [TagResource::class, 'index'],
    'contact fields' => [CustomFieldResource::class, 'index'],
    'company fields' => [CompanyCustomFieldResource::class, 'index'],
    'company types' => [CompanyTypeResource::class, 'index'],
]);

dataset('forgedSearchRelationManagers', [
    'company contacts' => [CompanyContactsRelationManager::class, 'company', EditCompany::class],
    'company deals' => [DealsRelationManager::class, 'company', EditCompany::class],
    'segment contacts' => [SegmentContactsRelationManager::class, 'segment', ViewSegment::class],
]);

test('an array search in the URL is a 200 on every list and the board', function (string $resource, string $page, string $query) {
    $this->get($resource::getUrl($page, tenant: $this->org).'?'.$query)->assertOk();
})->with('forgedSearchUrls')->with([
    'list' => ['search[]=x'],
    'map' => ['search[a][b]=1'],
]);

test('an array sort in the URL is a 200', function () {
    $this->get(ContactResource::getUrl('index', tenant: $this->org).'?sort[]=x')->assertOk();
});

test('an array search from the URL becomes an empty search', function (string $page) {
    foreach ([['x'], ['a' => ['b' => '1']]] as $junk) {
        $component = Livewire::withQueryParams(['search' => $junk])->test($page)->assertSuccessful();

        expect($component->get('tableSearch'))->toBe('');
    }
})->with('forgedFilterPages');

test('an array search set by an update becomes an empty search', function (string $page) {
    $component = Livewire::test($page)->set('tableSearch', ['x'])->assertSuccessful();

    expect($component->get('tableSearch'))->toBe('');

    $component->set('tableSearch', ['a' => ['b' => '1']])->assertSuccessful();

    expect($component->get('tableSearch'))->toBe('');
})->with('forgedFilterPages');

test('the members page and the invitations list ignore an array search', function (string $component) {
    $tested = Livewire::test($component)->set('tableSearch', ['x'])->assertSuccessful();

    expect($tested->get('tableSearch'))->toBe('');
})->with([
    'members' => [OrganizationMembers::class],
    'invitations' => [InvitationsList::class],
]);

test('the contacts list ignores a forged search and still lists everyone', function () {
    Livewire::test(ListContacts::class)
        ->set('tableSearch', ['Ann'])
        ->assertCanSeeTableRecords([$this->ann, $this->bob]);
});

test('a scalar search still works', function () {
    $component = Livewire::test(ListContacts::class)
        ->set('tableSearch', 'Ann')
        ->assertCanSeeTableRecords([$this->ann])
        ->assertCanNotSeeTableRecords([$this->bob]);

    expect($component->get('tableSearch'))->toBe('Ann');

    $component->set('tableSearch', 123);

    expect($component->get('tableSearch'))->toBe('123');

    Livewire::withQueryParams(['search' => 'Bob'])->test(ListContacts::class)
        ->assertCanSeeTableRecords([$this->bob])
        ->assertCanNotSeeTableRecords([$this->ann]);
});

test('a relation manager ignores an array search', function (string $manager, string $owner, string $page) {
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme']);
    $this->ann->companies()->attach($company->id);
    $this->annDeal->update(['company_id' => $company->id]);
    $segment = Segment::factory()->published()->create(['organization_id' => $this->org->id, 'name' => 'Everyone']);
    $segment->contacts()->attach($this->ann->id);

    $component = Livewire::test($manager, ['ownerRecord' => $owner === 'company' ? $company : $segment, 'pageClass' => $page])
        ->set('tableSearch', ['x'])
        ->assertSuccessful();

    expect($component->get('tableSearch'))->toBe('');
})->with('forgedSearchRelationManagers');

test('typed table state keeps the 419 of Livewire for an array update', function (string $page, string $property) {
    config(['app.debug' => false]);

    Livewire::test($page)->set($property, ['x'])->assertStatus(419);
})->with([
    'sort of the contacts' => [ListContacts::class, 'tableSort'],
    'sort of the board' => [DealPipeline::class, 'tableSort'],
    'tab of the deals' => [ListDeals::class, 'activeTab'],
]);

test('the typed state of a relation manager keeps the 419 of Livewire', function () {
    config(['app.debug' => false]);
    $company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme']);

    Livewire::test(CompanyContactsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class])
        ->set('tableSort', ['x'])
        ->assertStatus(419);
});

test('a nested column search is harmless', function () {
    Livewire::test(ListContacts::class)
        ->set('tableColumnSearches', ['name' => ['a' => ['b' => 'c']]])
        ->assertSuccessful();
});

test('an impossible calendar date in the invoices filter URL is dropped, not rolled over', function (string $date) {
    $january = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->ann->id,
        'status' => InvoiceStatus::Sent,
        'issued_at' => '2026-01-10',
    ]);
    $march = Invoice::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->ann->id,
        'status' => InvoiceStatus::Sent,
        'issued_at' => '2026-03-10',
    ]);

    $page = Livewire::withQueryParams(['filters' => ['issued_at_range' => ['until' => $date]]])->test(ListInvoices::class)->assertSuccessful();

    expect($page->get('tableFilters.issued_at_range.until'))->toBeNull();

    $page->assertCanSeeTableRecords([$january, $march]);
})->with(['2026-02-30', '2026-13-01', '2026-04-31']);
