<?php

use App\Exceptions\DuplicateCustomFieldValueException;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Organization;
use App\Services\ContactImportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->field = CustomField::factory()->for($this->org)->create(['name' => 'Customer number', 'type' => 'text', 'unique' => true]);
    $this->ann = Contact::factory()->for($this->org)->create(['name' => 'Ann', 'custom_field_values' => [$this->field->key => 'C-1']]);

    $this->createContact = fn (array $values, ?string $email = null): Contact => Contact::create([
        'organization_id' => $this->org->id,
        'name' => 'New',
        'email' => $email ?? fake()->unique()->safeEmail(),
        'custom_field_values' => $values,
    ]);

    $this->queriesMatching = function (string $needle, Closure $callback): array {
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries, $needle): void {
            if (str_contains($query->sql, $needle)) {
                $queries[] = $query;
            }
        });
        $callback();

        return $queries;
    };
});

describe('contacts', function () {
    it('refuses a duplicate saved through the model', function () {
        $exception = null;

        try {
            ($this->createContact)([$this->field->key => 'C-1'], 'bob@example.com');
        } catch (DuplicateCustomFieldValueException $exception) {
        }

        expect($exception)->toBeInstanceOf(DuplicateCustomFieldValueException::class)
            ->and($exception->fieldName)->toBe('Customer number')
            ->and($exception->fieldKey)->toBe($this->field->key)
            ->and($exception->getMessage())->toBe('The Customer number must be unique.')
            ->and(Contact::withoutGlobalScope('organization')->where('email', 'bob@example.com')->exists())->toBeFalse();
    });

    it('refuses a duplicate set on an existing record', function () {
        $bob = ($this->createContact)([$this->field->key => 'C-2']);

        expect(fn () => $bob->update(['custom_field_values' => [$this->field->key => 'C-1']]))
            ->toThrow(DuplicateCustomFieldValueException::class)
            ->and($bob->fresh()->customFieldValue($this->field->key))->toBe('C-2');
    });

    it('does not lock or even look at the definitions when no custom field value changed', function () {
        $queries = ($this->queriesMatching)('custom_field', function () {
            $this->ann->update(['name' => 'Ann B.']);
        });

        expect($queries)->toBe([])
            ->and($this->ann->fresh()->name)->toBe('Ann B.');
    });

    it('does not lock when only another custom field value changed', function () {
        $industry = CustomField::factory()->for($this->org)->create(['type' => 'text', 'unique' => false]);

        $locks = ($this->queriesMatching)('pg_advisory_xact_lock', function () use ($industry) {
            $this->ann->update(['custom_field_values' => [$this->field->key => 'C-1', $industry->key => 'Software']]);
        });

        expect($locks)->toBe([])
            ->and($this->ann->fresh()->customFieldValue($industry->key))->toBe('Software');
    });

    it('does not lock when the record has no custom field values', function () {
        $locks = ($this->queriesMatching)('pg_advisory_xact_lock', fn () => ($this->createContact)([]));

        expect($locks)->toBe([]);
    });

    it('locks the field and value of a unique value being set', function () {
        $locks = ($this->queriesMatching)('pg_advisory_xact_lock', fn () => ($this->createContact)([$this->field->key => 'C-9']));

        expect($locks)->toHaveCount(1)
            ->and($locks[0]->bindings)->toBe(["contacts|{$this->org->id}|{$this->field->key}|C-9"]);
    });

    it('lets a record keep its own value while other attributes change', function () {
        $this->ann->update(['phone' => '+33123456789', 'custom_field_values' => [$this->field->key => 'C-1']]);

        expect($this->ann->fresh()->phone)->toBe('+33123456789');
    });

    it('does not treat blank values as taken', function () {
        ($this->createContact)([]);
        ($this->createContact)([$this->field->key => '']);
        ($this->createContact)([]);

        expect(Contact::withoutGlobalScope('organization')->where('organization_id', $this->org->id)->count())->toBe(4);
    });

    it('ignores soft-deleted records', function () {
        $this->ann->delete();

        $contact = ($this->createContact)([$this->field->key => 'C-1']);

        expect($contact->exists)->toBeTrue();
    });

    it('only compares the records of the same organization', function () {
        $other = Organization::factory()->create();
        $otherField = CustomField::factory()->for($other)->create(['type' => 'text', 'unique' => true]);

        $contact = Contact::create(['organization_id' => $other->id, 'name' => 'Other', 'email' => 'other@example.com', 'custom_field_values' => [$otherField->key => 'C-1']]);

        expect($contact->exists)->toBeTrue();
    });

    it('does not guard fields that are not unique', function () {
        $industry = CustomField::factory()->for($this->org)->create(['type' => 'text', 'unique' => false]);
        ($this->createContact)([$industry->key => 'Software']);

        $locks = ($this->queriesMatching)('pg_advisory_xact_lock', fn () => ($this->createContact)([$industry->key => 'Software']));

        expect($locks)->toBe([]);
    });

    it('compares multi-select values as a whole list', function () {
        $codes = CustomField::factory()->for($this->org)->multiselect()->create(['name' => 'Codes', 'unique' => true]);
        ($this->createContact)([$codes->key => ['a', 'b']]);

        expect(fn () => ($this->createContact)([$codes->key => ['a', 'b']]))->toThrow(DuplicateCustomFieldValueException::class)
            ->and(($this->createContact)([$codes->key => ['a']])->exists)->toBeTrue();
    });

    it('locks several unique fields in a stable order', function () {
        $vat = CustomField::factory()->for($this->org)->create(['name' => 'VAT', 'type' => 'text', 'unique' => true]);
        [$first, $second] = collect([$this->field, $vat])->sortByDesc('key')->values()->all();
        $first->update(['order' => 1]);
        $second->update(['order' => 2]);

        $locks = ($this->queriesMatching)('pg_advisory_xact_lock', fn () => ($this->createContact)([$this->field->key => 'C-5', $vat->key => 'FR-5']));

        $keys = array_map(fn (QueryExecuted $query): string => $query->bindings[0], $locks);
        $sorted = $keys;
        sort($sorted);

        expect($keys)->toHaveCount(2)
            ->and($keys)->toBe($sorted);
    });
});

describe('companies', function () {
    it('are enforced too', function () {
        $type = CompanyType::factory()->create(['organization_id' => $this->org->id]);
        $siret = CompanyCustomField::factory()->create(['organization_id' => $this->org->id, 'name' => 'SIRET', 'type' => 'text', 'unique' => true]);
        $create = fn (): Company => Company::create(['organization_id' => $this->org->id, 'company_type_id' => $type->id, 'name' => 'Acme', 'custom_field_values' => [$siret->key => '123']]);
        $create();

        expect($create)->toThrow(DuplicateCustomFieldValueException::class, 'The SIRET must be unique.');
    });
});

describe('import', function () {
    it('reports a value taken right after the pre-check as a failed row', function () {
        app(TenantContext::class)->set($this->org->id);
        $service = new ContactImportService($this->org->id);
        $row = ['name' => 'Jane', 'email' => 'jane@example.com', 'Customer number' => 'C-7'];
        insertCompetingRowAfterUniquenessCheck('contacts', storedRowOf(Contact::factory()->make([
            'organization_id' => $this->org->id,
            'email' => 'competitor@example.com',
            'custom_field_values' => [$this->field->key => 'C-7'],
        ])), 'C-7');

        $result = $service->processRow($row, ['Customer number' => $this->field]);

        expect($result)->toBe(['success' => false, 'error' => "Duplicate value for unique field 'Customer number'."])
            ->and(Contact::forOrganization($this->org->id)->whereCustomFieldValue($this->field->key, 'C-7')->pluck('email')->all())->toBe(['competitor@example.com'])
            ->and(Contact::forOrganization($this->org->id)->where('email', 'jane@example.com')->exists())->toBeFalse();
    });
});
