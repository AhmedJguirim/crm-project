<?php

namespace App\Services;

use App\Enums\CompanyIndustry;
use App\Exceptions\DuplicateCustomFieldValueException;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Services\Companies\CompanyMatcher;
use App\Services\Imports\ImportCellParser;
use App\Support\CsvDialect;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reads the rows of a companies import file and creates the companies. An existing company is never updated: its row
 * fails.
 */
class CompanyImportService
{
    /**
     * The columns of a companies file that are not custom fields, in template order. A company custom field is
     * matched by its plain name.
     */
    public const COLUMNS = ['name', 'website', 'type', 'phone', 'industry', 'employees', 'annual revenue', 'street', 'city', 'zip', 'country', 'notes'];

    /** The record id column of an exported file; read by the update modes of the import. */
    public const ID_COLUMN = ContactImportService::ID_COLUMN;

    /** The columns the failed rows file adds in front of the original ones, so a corrected file can be imported again. */
    public const FAILED_ROWS_META_COLUMNS = ContactImportService::FAILED_ROWS_META_COLUMNS;

    /** The length of the `companies.name`, `companies.website` and address columns. */
    private const TEXT_MAX_LENGTH = 255;

    private const PHONE_MAX_LENGTH = 50;

    private readonly ImportCellParser $parser;

    private readonly CompanyMatcher $matcher;

    /** @var Collection<int, CompanyCustomField>|null */
    private ?Collection $customFields = null;

    /** @var Collection<int, CompanyType>|null */
    private ?Collection $companyTypes = null;

    /** @var array<string, int>|null */
    private ?array $companyTypeIds = null;

    /**
     * @param  CsvDialect|null  $dialect  How the file is written (see `ImportCellParser`).
     */
    public function __construct(
        private readonly int $organizationId,
        ?CsvDialect $dialect = null,
    ) {
        $this->parser = new ImportCellParser($organizationId, $dialect);
        $this->matcher = CompanyMatcher::forOrganization($organizationId);
    }

    /**
     * The active company custom fields, in column order. Loaded once per import; the template uses the same list, so
     * its columns are the ones the import matches.
     *
     * @return Collection<int, CompanyCustomField>
     */
    public function customFields(): Collection
    {
        return $this->customFields ??= CompanyCustomField::forOrganization($this->organizationId)
            ->orderBy('order')
            ->get();
    }

    /**
     * The active company types of the organization, by name. Loaded once per import.
     *
     * @return Collection<int, CompanyType>
     */
    public function companyTypes(): Collection
    {
        return $this->companyTypes ??= CompanyType::forOrganization($this->organizationId)
            ->orderBy('name')
            ->get();
    }

    /**
     * The canonical column of each header, in the same order: a column of `COLUMNS` (lowercase) or the exact name of an
     * active custom field, found ignoring case and surrounding spaces. Other headers stay as they are.
     *
     * @param  array<int, string>  $headers
     * @return array<int, string>
     */
    public function canonicalHeaders(array $headers): array
    {
        $canonical = $this->canonicalColumnsByKey();

        return array_map(
            fn (string $header): string => $canonical[$this->headerKey($header)] ?? $header,
            $headers,
        );
    }

    /**
     * The first column that two headers of the file map to ("name" and "Name "), or null. The columns of the failed
     * rows file are never a duplicate: files downloaded before they were written once still carry them twice.
     *
     * @param  array<int, string>  $headers
     */
    public function duplicatedColumn(array $headers): ?string
    {
        $known = array_values(array_diff($this->canonicalColumnsByKey(), self::FAILED_ROWS_META_COLUMNS));

        $counts = array_count_values(array_filter(
            $this->canonicalHeaders($headers),
            fn (string $column): bool => in_array($column, $known, true),
        ));

        return array_search(true, array_map(fn (int $count): bool => $count > 1, $counts), true) ?: null;
    }

    /**
     * The headers of a file that no column uses, as the notification names them; one that matches the name of a deleted
     * company custom field says so.
     *
     * @param  array<int, string>  $headers
     * @return array<int, string>
     */
    public function ignoredColumns(array $headers): array
    {
        $known = $this->canonicalColumnsByKey();

        $ignored = array_values(array_unique(array_filter(
            $headers,
            fn (string $header): bool => $header !== '' && ! isset($known[$this->headerKey($header)]),
        )));

        if ($ignored === []) {
            return [];
        }

        $deleted = array_map(
            $this->headerKey(...),
            CompanyCustomField::forOrganization($this->organizationId)->onlyTrashed()->pluck('name')->all(),
        );

        return array_map(
            fn (string $header): string => in_array($this->headerKey($header), $deleted, true) ? "\"{$header}\" (deleted field)" : "\"{$header}\"",
            $ignored,
        );
    }

    /**
     * Process a single row: the company, its address and its custom fields are created in one transaction. Once it
     * has committed, the company is remembered, so a later row naming the same company fails as already existing.
     *
     * @param  array<string, string>  $row  Keyed by canonical column.
     * @return array{success: bool, error: ?string}
     */
    public function processRow(array $row): array
    {
        $name = trim($row['name'] ?? '');
        $website = trim($row['website'] ?? '');

        if ($name === '') {
            return $this->failure('Name is required.');
        }

        foreach (['name' => $name, 'website' => $website] as $column => $value) {
            if (mb_strlen($value) > self::TEXT_MAX_LENGTH) {
                return $this->failure("Invalid value for field '{$column}': {$value}");
            }
        }

        if ($website !== '' && Company::domainFrom($website) === null) {
            return $this->failure("Invalid value for field 'website': {$website}");
        }

        $match = $this->matcher->match($name, $website);

        if ($match->isFailed()) {
            return $this->failure($match->reason);
        }

        if ($match->isFound()) {
            return $this->failure($this->alreadyExistsMessage($match->company));
        }

        $details = $this->detailsFromRow($row);

        if ($details['error'] !== null) {
            return $this->failure($details['error']);
        }

        try {
            $company = DB::transaction(function () use ($name, $website, $details): Company {
                $address = $details['address'] === null
                    ? null
                    : Address::create(['organization_id' => $this->organizationId, ...$details['address']]);

                return Company::create([
                    'organization_id' => $this->organizationId,
                    'name' => $name,
                    'website' => $website,
                    'address_id' => $address?->getKey(),
                    ...$details['attributes'],
                ]);
            });
        } catch (DuplicateCustomFieldValueException $exception) {
            return $this->failure("Duplicate value for unique field '{$exception->fieldName}'.");
        }

        $this->matcher->remember($company);

        return ['success' => true, 'error' => null];
    }

    /**
     * The values the company gets from the cells of the row, or the error that fails the row.
     *
     * @param  array<string, string>  $row
     * @return array{attributes: array<string, mixed>, address: ?array<string, string>, error: ?string}
     */
    private function detailsFromRow(array $row): array
    {
        $attributes = ['custom_field_values' => []];
        $address = [];

        foreach (array_slice(self::COLUMNS, 2) as $column) {
            $raw = trim($row[$column] ?? '');

            if ($raw === '') {
                continue;
            }

            $value = $this->columnValue($column, $raw, $row[$column]);

            if ($value === null) {
                return $this->detailsError("Invalid value for field '{$column}': {$raw}");
            }

            match ($column) {
                'type' => $attributes['company_type_id'] = $value,
                'phone' => $attributes['phone'] = $value,
                'industry' => $attributes['industry'] = $value,
                'employees' => $attributes['employees'] = $value,
                'annual revenue' => $attributes['annual_revenue'] = $value,
                'notes' => $attributes['notes'] = $value,
                default => $address[$column] = $value,
            };
        }

        foreach ($this->customFields() as $field) {
            $rawValue = $row[$field->name] ?? null;

            if (blank($rawValue) || ($field->type === 'multiselect' && $this->parser->splitMultiValue($rawValue) === [])) {
                continue;
            }

            $value = $this->parser->castFieldValue($field, $rawValue);

            if ($value === null) {
                return $this->detailsError("Invalid value for field '{$field->name}': {$rawValue}");
            }

            if ($field->unique && Company::forOrganization($this->organizationId)->whereCustomFieldValue($field->key, $value)->exists()) {
                return $this->detailsError("Duplicate value for unique field '{$field->name}'.");
            }

            $attributes['custom_field_values'][$field->key] = $value;
        }

        return ['attributes' => $attributes, 'address' => $address === [] ? null : $address, 'error' => null];
    }

    /**
     * The value a built-in column designates, or null when the cell is not valid.
     */
    private function columnValue(string $column, string $trimmed, string $raw): mixed
    {
        return match ($column) {
            'type' => $this->companyTypeIds()[mb_strtolower($trimmed)] ?? null,
            'phone' => mb_strlen($trimmed) <= self::PHONE_MAX_LENGTH ? $trimmed : null,
            'industry' => $this->parser->enumFromCell(CompanyIndustry::class, $trimmed),
            'employees' => $this->parser->parseEmployees($trimmed),
            'annual revenue' => $this->parser->parseAnnualRevenue($trimmed),
            'notes' => $raw,
            default => mb_strlen($trimmed) <= self::TEXT_MAX_LENGTH ? $trimmed : null,
        };
    }

    /**
     * @return array<string, int>
     */
    private function companyTypeIds(): array
    {
        return $this->companyTypeIds ??= $this->companyTypes()
            ->reverse()
            ->mapWithKeys(fn (CompanyType $type): array => [mb_strtolower(trim($type->name)) => $type->getKey()])
            ->all();
    }

    private function alreadyExistsMessage(Company $company): string
    {
        return $company->domain === null
            ? "A company named {$company->name} already exists."
            : "A company named {$company->name} ({$company->domain}) already exists.";
    }

    /**
     * @return array{success: false, error: string}
     */
    private function failure(string $error): array
    {
        return ['success' => false, 'error' => $error];
    }

    /**
     * @return array{attributes: array<string, mixed>, address: null, error: string}
     */
    private function detailsError(string $error): array
    {
        return ['attributes' => [], 'address' => null, 'error' => $error];
    }

    /**
     * The canonical column of every header the import knows, by the lowercase trimmed header.
     *
     * @return array<string, string>
     */
    private function canonicalColumnsByKey(): array
    {
        $columns = [];

        foreach ($this->customFields()->pluck('name')->all() as $name) {
            $columns[$this->headerKey($name)] ??= $name;
        }

        foreach ([...self::COLUMNS, ...self::FAILED_ROWS_META_COLUMNS, self::ID_COLUMN] as $column) {
            $columns[$column] = $column;
        }

        return $columns;
    }

    private function headerKey(string $header): string
    {
        return mb_strtolower(trim($header));
    }
}
