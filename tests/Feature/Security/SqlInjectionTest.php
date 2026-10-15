<?php

use App\Data\Segments\SegmentConditionData;
use App\Data\Segments\SegmentRuleData;
use App\Enums\ContactAttribute;
use App\Enums\InvoiceStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use App\Exceptions\DuplicateCustomFieldValueException;
use App\Filament\Pages\Tenancy\EditOrganization;
use App\Filament\Resources\Companies\Pages\EditCompany;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\RelationManagers\ContactsRelationManager as CompanyContactsRelationManager;
use App\Filament\Resources\Companies\RelationManagers\DealsRelationManager;
use App\Filament\Resources\CompanyCustomFields\Pages\ListCompanyCustomFields;
use App\Filament\Resources\CompanyTypes\Pages\ManageCompanyTypes;
use App\Filament\Resources\Contacts\Pages\ListContacts;
use App\Filament\Resources\CustomFields\Pages\ListCustomFields;
use App\Filament\Resources\Deals\Pages\DealPipeline;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Segments\Pages\ListSegments;
use App\Filament\Resources\Segments\Pages\SegmentRuleEngine;
use App\Filament\Resources\Segments\Pages\ViewSegment;
use App\Filament\Resources\Segments\RelationManagers\ContactsRelationManager as SegmentContactsRelationManager;
use App\Filament\Resources\Tags\Pages\ListTags;
use App\Filament\Resources\Tasks\Pages\ListTasks;
use App\Jobs\ProcessCompanyImportJob;
use App\Jobs\ProcessContactImportJob;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Services\Segments\Conditions\AttributeConditionCompiler;
use App\Services\Segments\Conditions\CustomFieldConditionCompiler;
use App\Services\Segments\SegmentQueryBuilder;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Livewire\GlobalSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\Feature\Security\SecurityPayloads;

dataset('sqlListPages', [
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

dataset('sqlRelationManagers', [
    'company contacts' => ['company', CompanyContactsRelationManager::class],
    'company deals' => ['company', DealsRelationManager::class],
    'segment contacts' => ['segment', SegmentContactsRelationManager::class],
]);

beforeEach(function () {
    $this->owner = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Test Owner']);
    $this->org = $this->owner->personalOrganization();
    $this->actingAs($this->owner);
    Filament::setTenant($this->org);

    $this->ann = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ann Lee']);
    $this->bob = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => 'Bob Ray']);

    $this->deal = Deal::factory()->inStage('Lead')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->ann->id,
        'owner_id' => $this->owner->id,
        'created_by' => $this->owner->id,
        'title' => 'Ann deal',
    ]);
    $this->otherDeal = Deal::factory()->inStage('Discovery')->create([
        'organization_id' => $this->org->id,
        'contact_id' => $this->bob->id,
        'owner_id' => null,
        'created_by' => $this->owner->id,
        'title' => 'Bob deal',
    ]);
    Task::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->ann->id, 'created_by' => $this->owner->id, 'title' => 'Call Ann']);
    Invoice::factory()->create(['organization_id' => $this->org->id, 'contact_id' => $this->ann->id, 'status' => InvoiceStatus::Sent]);
    Tag::factory()->create(['organization_id' => $this->org->id, 'name' => 'VIP']);
    $this->companyType = CompanyType::factory()->create(['organization_id' => $this->org->id, 'name' => 'SME']);
    $this->company = Company::factory()->create(['organization_id' => $this->org->id, 'name' => 'Acme']);
    $this->ann->companies()->attach($this->company->id);
    $this->deal->update(['company_id' => $this->company->id]);
    $this->nickname = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Nickname', 'type' => 'text', 'unique' => false, 'order' => 1]);
    CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ref', 'type' => 'text', 'unique' => false, 'order' => 1]);
    $this->segment = Segment::factory()->published()->create(['organization_id' => $this->org->id, 'name' => 'Everyone']);
    $this->segment->contacts()->attach($this->ann->id);

    $this->gina = User::factory()->onboardingCompleted()->withPersonalOrganization()->create(['name' => 'Gina Globex']);
    $this->globex = $this->gina->personalOrganization();

    $this->globexContact = fn (string $name): Contact => Contact::factory()->create([
        'organization_id' => $this->globex->id,
        'name' => $name,
    ]);
});

/**
 * The records the table of a Livewire test component currently lists.
 *
 * @return Collection<int, Model>
 */
function sqlInjectionRecords(mixed $component): Collection
{
    $records = $component->instance()->getTableRecords();

    return collect($records instanceof Collection ? $records->all() : $records->items());
}

/**
 * @param  Collection<int, Model>  $records
 * @return array<int, int|string>
 */
function sqlInjectionKeys(Collection $records): array
{
    return $records->map(fn ($record) => $record->getKey())->sort()->values()->all();
}

function sqlInjectionCondition(SegmentConditionType $type, ?string $field, SegmentOperator $operator, array $value = []): SegmentConditionData
{
    return SegmentConditionData::make($type, $field, $operator, $value);
}

describe('table search, sort and filters', function () {
    test('a payload typed in the search box of a list', function (string $page, string $payload) {
        ($this->globexContact)($payload);
        $before = DB::table('contacts')->count();
        $records = collect();

        $sql = SecurityPayloads::queriesDuring(function () use ($page, $payload, &$records): void {
            $component = Livewire::test($page)->assertSuccessful()->set('tableSearch', $payload);
            $records = sqlInjectionRecords($component);
        });

        expect($sql)->not->toBe([])
            ->and($records->every(fn ($record) => $record->organization_id === $this->org->id))->toBeTrue()
            ->and(DB::table('contacts')->count())->toBe($before);
        SecurityPayloads::assertSqlIsClean($sql, $payload);
    })->with('sqlListPages')->with(SecurityPayloads::sql());

    test('a contact named like the payload is found literally', function (string $payload) {
        $own = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
        ($this->globexContact)($payload);

        $records = sqlInjectionRecords(Livewire::test(ListContacts::class)->set('tableSearch', $payload));

        expect(sqlInjectionKeys($records))->toBe([$own->id]);
    })->with(collect(SecurityPayloads::sql())->except('backslash')->all());

    test('a forged sort column and direction is ignored', function (string $page, string $payload) {
        $baseline = sqlInjectionKeys(sqlInjectionRecords(Livewire::test($page)));
        $sorted = [];

        $sql = SecurityPayloads::queriesDuring(function () use ($page, $payload, &$sorted): void {
            foreach ([$payload.':asc', 'name:'.$payload] as $sort) {
                $component = Livewire::test($page)->set('tableSort', $sort)->assertSuccessful();
                $sorted[] = sqlInjectionKeys(sqlInjectionRecords($component));
            }
        });

        expect($baseline)->not->toBe([])
            ->and($sorted)->toBe([$baseline, $baseline]);
        SecurityPayloads::assertSqlIsClean($sql, $payload);
    })->with('sqlListPages')->with(SecurityPayloads::sql());

    test('a payload as the value of an id filter and of an option filter is ignored', function (string $payload) {
        $component = Livewire::test(ListDeals::class)
            ->set('tableFilters.contact_id.value', $payload)
            ->set('tableFilters.pipeline_stage_id.values', [$payload])
            ->assertCanSeeTableRecords([$this->deal, $this->otherDeal]);

        expect($component->instance()->getTable()->getActiveFiltersCount())->toBe(0);
    })->with(SecurityPayloads::sql());
});

describe('relation managers and global search', function () {
    test('a relation manager is searched and sorted with a payload', function (string $owner, string $manager, string $payload) {
        ($this->globexContact)($payload);
        $record = $owner === 'company' ? $this->company : $this->segment;
        $page = $owner === 'company' ? EditCompany::class : ViewSegment::class;
        $before = DB::table('contacts')->count();
        $baseline = sqlInjectionKeys(sqlInjectionRecords(Livewire::test($manager, ['ownerRecord' => $record, 'pageClass' => $page])));
        $listed = [];

        $sql = SecurityPayloads::queriesDuring(function () use ($manager, $record, $page, $payload, $baseline, &$listed): void {
            $searched = Livewire::test($manager, ['ownerRecord' => $record, 'pageClass' => $page])->set('tableSearch', $payload)->assertSuccessful();
            $listed[] = sqlInjectionRecords($searched);

            foreach ([$payload.':asc', 'name:'.$payload] as $sort) {
                $sorted = Livewire::test($manager, ['ownerRecord' => $record, 'pageClass' => $page])->set('tableSort', $sort)->assertSuccessful();
                expect(sqlInjectionKeys(sqlInjectionRecords($sorted)))->toBe($baseline);
            }
        });

        expect($baseline)->not->toBe([])
            ->and($listed[0]->every(fn ($row) => $row->organization_id === $this->org->id))->toBeTrue()
            ->and(DB::table('contacts')->count())->toBe($before);
        SecurityPayloads::assertSqlIsClean($sql, $payload);
    })->with('sqlRelationManagers')->with(SecurityPayloads::sql());

    test('the global search runs a payload', function (string $payload) {
        ($this->globexContact)($payload);
        $before = DB::table('contacts')->count();
        $titles = [];

        $sql = SecurityPayloads::queriesDuring(function () use ($payload, &$titles): void {
            $component = Livewire::test(GlobalSearch::class)->set('search', $payload)->assertSuccessful();
            $results = $component->instance()->getResults();

            foreach ($results?->getCategories() ?? [] as $category) {
                foreach ($category as $result) {
                    $titles[] = (string) $result->title;
                }
            }
        });

        expect($titles)->not->toContain($payload)
            ->and(DB::table('contacts')->count())->toBe($before);
        SecurityPayloads::assertSqlIsClean($sql, $payload);
    })->with(SecurityPayloads::sql());

    test('the global search finds a contact of the organization named like the payload', function (string $payload) {
        $own = Contact::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
        ($this->globexContact)($payload);

        $results = Livewire::test(GlobalSearch::class)->set('search', $payload)->instance()->getResults();
        $titles = collect($results?->getCategories() ?? [])->flatten()->map(fn ($result) => (string) $result->title)->all();

        expect($titles)->toContain($own->name)
            ->and(array_count_values($titles)[$payload] ?? 0)->toBe(1);
    })->with(collect(SecurityPayloads::sql())->except('backslash')->all());
});

describe('segment rules', function () {
    beforeEach(function () {
        $this->builder = SegmentQueryBuilder::forOrganization($this->org->id);
        $this->numberField = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Score', 'type' => 'number', 'unique' => false, 'order' => 2]);
    });

    test('text conditions bind the payload', function (string $payload) {
        Contact::factory()->create([
            'organization_id' => $this->org->id,
            'name' => $payload,
            'custom_field_values' => [$this->nickname->key => $payload],
        ]);
        Contact::factory()->create([
            'organization_id' => $this->globex->id,
            'name' => $payload,
            'custom_field_values' => [$this->nickname->key => $payload],
        ]);
        $others = Contact::forOrganization($this->org->id)->count() - 1;
        $counts = [];

        $sql = SecurityPayloads::queriesDuring(function () use ($payload, &$counts): void {
            foreach ([SegmentOperator::Is, SegmentOperator::IsNot, SegmentOperator::Contains, SegmentOperator::DoesNotContain, SegmentOperator::StartsWith, SegmentOperator::EndsWith] as $operator) {
                $counts[$operator->value] = [
                    'name' => $this->builder->countConditions([sqlInjectionCondition(SegmentConditionType::Attribute, ContactAttribute::Name->value, $operator, ['value' => $payload])]),
                    'custom field' => $this->builder->countConditions([sqlInjectionCondition(SegmentConditionType::CustomField, $this->nickname->key, $operator, ['value' => $payload])]),
                ];
            }
        });

        expect($counts)->toBe([
            'is' => ['name' => 1, 'custom field' => 1],
            'is_not' => ['name' => $others, 'custom field' => $others],
            'contains' => ['name' => 1, 'custom field' => 1],
            'does_not_contain' => ['name' => $others, 'custom field' => $others],
            'starts_with' => ['name' => 1, 'custom field' => 1],
            'ends_with' => ['name' => 1, 'custom field' => 1],
        ]);
        SecurityPayloads::assertSqlIsClean($sql, $payload);
    })->with(SecurityPayloads::sql());

    test('a payload where a number, a date or a field is expected matches nothing', function (string $payload) {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $before = DB::table('contacts')->count();
        $counts = [];

        $sql = SecurityPayloads::queriesDuring(function () use ($payload, &$counts): void {
            $conditions = [
                'number' => sqlInjectionCondition(SegmentConditionType::CustomField, $this->numberField->key, SegmentOperator::EqualTo, ['value' => $payload]),
                'created at on' => sqlInjectionCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::On, ['value' => $payload]),
                'created at between' => sqlInjectionCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::Between, ['value' => '2026-01-01', 'value_to' => $payload]),
                'attribute field' => sqlInjectionCondition(SegmentConditionType::Attribute, $payload, SegmentOperator::Is, ['value' => 'x']),
                'custom field key' => sqlInjectionCondition(SegmentConditionType::CustomField, $payload, SegmentOperator::Is, ['value' => 'x']),
            ];

            foreach ($conditions as $name => $condition) {
                $counts[$name] = $this->builder->countConditions([$condition]);
            }
        });

        expect($counts)->toBe(['number' => 0, 'created at on' => 0, 'created at between' => 0, 'attribute field' => 0, 'custom field key' => 0])
            ->and(DB::table('contacts')->count())->toBe($before);
        SecurityPayloads::assertSqlIsClean($sql, $payload);
    })->with(SecurityPayloads::sql());

    test('the rule engine page counts a payload rule', function () {
        $payload = "x' OR 1=1 --";
        Contact::factory()->create(['organization_id' => $this->org->id, 'name' => $payload]);
        $rule = new SegmentRuleData('rule-1', 'Payload', [
            new SegmentConditionData('condition-1', SegmentConditionType::Attribute, ContactAttribute::Name->value, SegmentOperator::Is, ['value' => $payload]),
        ]);
        $segment = Segment::factory()->for($this->org)->withRules([$rule])->create(['name' => 'Payload rules']);
        $count = null;

        $sql = SecurityPayloads::queriesDuring(function () use ($segment, &$count): void {
            $page = Livewire::test(SegmentRuleEngine::class, ['record' => $segment->getRouteKey()]);
            $count = $page->instance()->ruleMatchCount($segment->workingRules()->first());
        });

        expect($count)->toBe(1);
        SecurityPayloads::assertSqlIsClean($sql, $payload);
    });
});

describe('the sinks of the segment compilers', function () {
    test('the custom field compiler refuses a key that is not a plain identifier', function (string $key) {
        $condition = sqlInjectionCondition(SegmentConditionType::CustomField, $key, SegmentOperator::Is, ['value' => 'x']);

        expect(fn () => (new CustomFieldConditionCompiler('UTC'))->apply(Contact::query(), $condition, SegmentFieldKind::Text))
            ->toThrow(InvalidArgumentException::class, "Invalid custom field key [{$key}].");
    })->with([
        'trailing newline' => ["abc\n"],
        'quote and comment' => ["x' OR 1=1 --"],
        'dash' => ['a-b'],
        'empty' => [''],
    ]);

    test('the custom field compiler accepts a plain identifier', function () {
        $condition = sqlInjectionCondition(SegmentConditionType::CustomField, 'nickname_2', SegmentOperator::Is, ['value' => 'x']);

        (new CustomFieldConditionCompiler('UTC'))->apply(Contact::query(), $condition, SegmentFieldKind::Text);

        expect(true)->toBeTrue();
    });

    test('the attribute compiler refuses a forged timezone itself', function (string $timezone) {
        expect(fn () => new AttributeConditionCompiler($timezone))
            ->toThrow(InvalidArgumentException::class, 'Invalid organization timezone.');
    })->with([
        'injection' => ["UTC' AS date) OR true --"],
        'unknown' => ['Mars/Olympus'],
        'lowercase' => ['europe/paris'],
        'padded' => [' UTC'],
        'empty' => [''],
    ]);

    test('the attribute compiler accepts an IANA timezone and still filters by local date', function () {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        $this->org->update(['timezone' => 'Europe/Paris']);
        $this->ann->forceFill(['created_at' => '2026-10-13 23:30:00'])->save();

        $compiler = new AttributeConditionCompiler('Europe/Paris');
        $query = Contact::query()->whereKey($this->ann->id);
        $compiler->apply($query, sqlInjectionCondition(SegmentConditionType::Attribute, ContactAttribute::CreatedAt->value, SegmentOperator::On, ['value' => '2026-10-14']), SegmentFieldKind::Date);

        expect($query->count())->toBe(1);
    });

    test('the timezone form refuses a payload', function (string $payload) {
        Livewire::test(EditOrganization::class)
            ->fillForm(['timezone' => $payload])
            ->call('save')
            ->assertHasFormErrors(['timezone']);

        expect($this->org->fresh()->timezone)->toBe('UTC');
    })->with(SecurityPayloads::sql());
});

describe('custom field lookups', function () {
    test('key and value are bound', function (string $payload) {
        $code = CustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'Code', 'type' => 'text', 'unique' => true, 'order' => 5]);
        $globexCode = CustomField::factory()->create(['organization_id' => $this->globex->id, 'name' => 'Code', 'type' => 'text', 'unique' => true, 'order' => 1]);
        $this->ann->update(['custom_field_values' => [$code->key => $payload]]);
        $before = DB::table('contacts')->count();
        $exception = null;

        $sql = SecurityPayloads::queriesDuring(function () use ($payload, $code, $globexCode, &$exception): void {
            expect(Contact::query()->whereCustomFieldValue($payload, $payload)->count())->toBe(0)
                ->and(Contact::query()->whereCustomFieldValue($code->key, $payload)->pluck('name')->all())->toBe(['Ann Lee']);

            try {
                Contact::create(['organization_id' => $this->org->id, 'name' => 'Bob Two', 'email' => 'bob.two@example.test', 'custom_field_values' => [$code->key => $payload]]);
            } catch (DuplicateCustomFieldValueException $exception) {
            }

            Contact::create(['organization_id' => $this->globex->id, 'name' => 'Globex Code', 'email' => 'code@globex.test', 'custom_field_values' => [$globexCode->key => $payload]]);
        });

        expect($exception)->toBeInstanceOf(DuplicateCustomFieldValueException::class)
            ->and($exception->getMessage())->toBe('The Code must be unique.')
            ->and(DB::table('contacts')->count())->toBe($before + 1);
        SecurityPayloads::assertSqlIsClean($sql, $payload);
    })->with(SecurityPayloads::sql());
});

describe('imports', function () {
    /**
     * @return array<int, array<int, string>> one row per payload, then the NUL row, with a unique email
     */
    function sqlInjectionContactRows(): array
    {
        $names = [...array_map(fn (array $payload): string => $payload[0], array_values(SecurityPayloads::sql())), SecurityPayloads::NUL];
        $rows = [];

        foreach ($names as $index => $name) {
            $rows[] = [$name, "row{$index}@import.test", $name, $name];
        }

        return $rows;
    }

    test('a contact import stores payloads as typed', function (string $format) {
        Storage::fake('local');
        $this->segment->delete();
        $rows = sqlInjectionContactRows();
        $header = ['name', 'email', 'Nickname', 'company'];

        if ($format === 'xlsx') {
            $path = makeXlsx([$header, ...$rows]);
        } else {
            $path = 'contact-imports/payloads-'.uniqid().'.csv';
            Storage::disk('local')->makeDirectory('contact-imports');
            $writer = SimpleExcelWriter::create(Storage::disk('local')->path($path), 'csv', delimiter: ';');
            $writer->addRows(array_map(fn (array $row): array => array_combine($header, $row), $rows));
            $writer->close();
        }

        $before = DB::table('contacts')->count();
        $sql = SecurityPayloads::queriesDuring(fn () => ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->owner->id));

        expect(DB::table('contacts')->count())->toBe($before + count($rows));

        foreach ($rows as [$name, $email]) {
            $expected = str_replace("\0", '', $name);
            $contact = Contact::forOrganization($this->org->id)->where('email', $email)->sole();

            expect($contact->name)->toBe($expected)
                ->and($contact->customFieldValue($this->nickname->key))->toBe($expected)
                ->and($contact->companies()->withoutGlobalScope('organization')->pluck('name')->all())->toBe([$expected]);
        }

        expect(Contact::forOrganization($this->org->id)->where('email', 'row'.(count($rows) - 1).'@import.test')->value('name'))->toBe('xy');

        foreach (array_values(SecurityPayloads::sql()) as [$payload]) {
            SecurityPayloads::assertSqlIsClean($sql, $payload);
        }
    })->with(['xlsx', 'European CSV']);

    test('a contact import fails only the rows whose owner cell is a payload', function () {
        Storage::fake('local');
        $this->segment->delete();
        $payloads = array_map(fn (array $payload): string => $payload[0], array_values(SecurityPayloads::sql()));
        $rows = [['name', 'email', 'owner']];

        foreach ($payloads as $index => $payload) {
            $rows[] = ['Row '.$index, "owner{$index}@import.test", $payload];
        }

        $rows[] = ['Good Row', 'good@import.test', $this->owner->email];
        $path = makeXlsx($rows);
        $before = DB::table('contacts')->count();

        $sql = SecurityPayloads::queriesDuring(fn () => ProcessContactImportJob::dispatchSync($path, $this->org->id, $this->owner->id));

        expect(DB::table('contacts')->count())->toBe($before + 1)
            ->and(Contact::forOrganization($this->org->id)->where('email', 'good@import.test')->value('owner_id'))->toBe($this->owner->id);

        foreach ($payloads as $payload) {
            SecurityPayloads::assertSqlIsClean($sql, $payload);
        }
    });

    test('a company import fails only the rows whose owner cell is a payload', function () {
        Storage::fake('local');
        $this->segment->delete();
        $payloads = array_map(fn (array $payload): string => $payload[0], array_values(SecurityPayloads::sql()));
        $path = 'company-imports/owner-payloads-'.uniqid().'.xlsx';
        Storage::disk('local')->makeDirectory('company-imports');
        $writer = SimpleExcelWriter::create(Storage::disk('local')->path($path))->noHeaderRow();
        $writer->addRow(['name', 'owner']);

        foreach ($payloads as $index => $payload) {
            $writer->addRow(['Payload Co '.$index, $payload]);
        }

        $writer->addRow(['Good Co', $this->owner->email]);
        $writer->close();
        $before = DB::table('companies')->count();

        $sql = SecurityPayloads::queriesDuring(fn () => ProcessCompanyImportJob::dispatchSync($path, $this->org->id, $this->owner->id));

        expect(DB::table('companies')->count())->toBe($before + 1)
            ->and(Company::forOrganization($this->org->id)->where('name', 'Good Co')->value('owner_id'))->toBe($this->owner->id);

        foreach ($payloads as $payload) {
            SecurityPayloads::assertSqlIsClean($sql, $payload);
        }
    });

    test('a company import stores payloads as typed', function () {
        Storage::fake('local');
        $payloads = array_map(fn (array $payload): string => $payload[0], array_values(SecurityPayloads::sql()));
        $path = 'company-imports/payloads-'.uniqid().'.xlsx';
        Storage::disk('local')->makeDirectory('company-imports');
        $writer = SimpleExcelWriter::create(Storage::disk('local')->path($path))->noHeaderRow();
        $writer->addRow(['name', 'notes', 'city', 'Ref']);

        foreach ($payloads as $payload) {
            $writer->addRow([$payload, $payload, $payload, $payload]);
        }

        $writer->close();
        $ref = CompanyCustomField::query()->where('name', 'Ref')->sole();
        $this->segment->delete();

        $sql = SecurityPayloads::queriesDuring(fn () => ProcessCompanyImportJob::dispatchSync($path, $this->org->id, $this->owner->id));

        foreach ($payloads as $payload) {
            $company = Company::forOrganization($this->org->id)->where('name', $payload)->with('address')->sole();

            expect($company->notes)->toBe($payload)
                ->and($company->address?->city)->toBe($payload)
                ->and($company->customFieldValue($ref->key))->toBe($payload);
            SecurityPayloads::assertSqlIsClean($sql, $payload);
        }
    });
});
