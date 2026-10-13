<?php

use App\Enums\DealStage;
use App\Enums\InvoiceStatus;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\CompanyCustomFields\Pages\ListCompanyCustomFields;
use App\Filament\Resources\CompanyTypes\Pages\ManageCompanyTypes;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\Filters\RecordKeySelectFilter;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Test Owner']);
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee']);
    $this->bob = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob Ray']);

    $this->annDeal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->ann->id,
        'owner_id' => $this->owner->id,
        'created_by' => $this->owner->id,
        'title' => 'Ann deal',
        'stage' => DealStage::Lead,
    ]);
    $this->bobDeal = Deal::factory()->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->bob->id,
        'owner_id' => null,
        'created_by' => $this->owner->id,
        'title' => 'Bob deal',
        'stage' => DealStage::Discovery,
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

test('an unknown option is dropped and a known one kept', function () {
    Livewire::test(ListDeals::class)
        ->set('tableFilters.stage.values', [DealStage::Lead->value, 'bogus'])
        ->assertCanSeeTableRecords([$this->annDeal])
        ->assertCanNotSeeTableRecords([$this->bobDeal])
        ->set('tableFilters.stage.values', DealStage::Lead->value)
        ->assertCanSeeTableRecords([$this->annDeal, $this->bobDeal]);
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
