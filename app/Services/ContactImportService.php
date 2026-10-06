<?php

namespace App\Services;

use App\Enums\CompanyIndustry;
use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Exceptions\ContactAlreadyExistsException;
use App\Exceptions\DuplicateCustomFieldValueException;
use App\Models\Address;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Models\CompanyType;
use App\Models\Contact;
use App\Models\CustomField;
use App\Models\Tag;
use App\Services\Companies\CompanyMatch;
use App\Services\Companies\CompanyMatcher;
use App\Support\CsvDialect;
use BackedEnum;
use DateTime;
use DateTimeInterface;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ContactImportService
{
    /** Separates several tags, or several values of a multi-select field, in one cell. */
    public const MULTI_VALUE_SEPARATOR = ';';

    /**
     * The columns of an import file that are not custom fields: what the import reads, in template order. Add a column
     * here before the import reads it.
     */
    public const BASE_COLUMNS = ['name', 'email', 'phone', 'tags', 'status', 'lead source', 'company', 'company website', ...self::COMPANY_DETAIL_COLUMNS];

    /**
     * The columns that fill a company the row creates (the others name the company). The company custom fields add
     * one `company: <field name>` column each.
     */
    public const COMPANY_DETAIL_COLUMNS = [
        'company type',
        'company phone',
        'company industry',
        'company employees',
        'company annual revenue',
        'company street',
        'company city',
        'company zip',
        'company country',
    ];

    /** The names a custom field can't have: the columns the import reads. */
    public const RESERVED_COLUMNS = self::BASE_COLUMNS;

    /** The prefix of the columns holding a company custom field: `company: VAT Number`. */
    public const COMPANY_FIELD_PREFIX = 'company: ';

    /** The length of the `companies.name` and `companies.website` columns. */
    private const COMPANY_TEXT_MAX_LENGTH = 255;

    private const MAX_EMPLOYEES = 2147483647;

    private const MAX_ANNUAL_REVENUE = 9999999999999.99;

    /** The columns the failed rows file adds in front of the original ones, so a corrected file can be imported again. */
    public const FAILED_ROWS_META_COLUMNS = ['_row_number', '_error'];

    /** The date format shown to users; ISO (`Y-m-d`) and a trailing time are accepted too. */
    public const DATE_FORMAT = 'd-m-Y';

    /**
     * @param  CsvDialect|null  $dialect  How the file is written. A file with `;` between columns is a European Excel
     *                                    file, so its numbers may have a decimal comma and its dates may be d/m/Y or d.m.Y.
     */
    public function __construct(
        private readonly int $organizationId,
        private readonly ?CsvDialect $dialect = null,
    ) {}

    private ?CompanyMatcher $companyMatcher = null;

    private int $createdCompaniesCount = 0;

    private bool $companyDetailsIgnored = false;

    /** @var Collection<int, CompanyCustomField>|null */
    private ?Collection $companyCustomFields = null;

    /** @var Collection<int, CompanyType>|null */
    private ?Collection $companyTypes = null;

    /** @var array<string, int>|null */
    private ?array $companyTypeIds = null;

    /**
     * How many companies the rows processed so far created (only counted once their row was committed).
     */
    public function createdCompaniesCount(): int
    {
        return $this->createdCompaniesCount;
    }

    /**
     * Whether a row linked an existing company while some of its company columns were filled: they are never applied
     * to an existing company.
     */
    public function companyDetailsIgnored(): bool
    {
        return $this->companyDetailsIgnored;
    }

    /**
     * The active company custom fields, in column order (`company: <name>`). Loaded once per import.
     *
     * @return Collection<int, CompanyCustomField>
     */
    public function companyCustomFields(): Collection
    {
        return $this->companyCustomFields ??= CompanyCustomField::forOrganization($this->organizationId)
            ->orderBy('order')
            ->get();
    }

    /**
     * The active company types of the organization by name. Loaded once per import.
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
     * The custom fields an import of the organization fills, in column order. The import template uses the same
     * list, so its columns are the ones the import matches.
     *
     * @return Collection<int, CustomField>
     */
    public function customFields(): Collection
    {
        return CustomField::where('organization_id', $this->organizationId)
            ->orderBy('order')
            ->get();
    }

    /**
     * The canonical column of each header, in the same order: a base column (lowercase) or the exact name of an active
     * custom field, found ignoring case and surrounding spaces. Other headers stay as they are.
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
     * The first column that two headers of the file map to ("email" and "Email "), or null. The columns of the failed
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
     * The headers of a file that no field uses, as the notification names them. A header matches the base columns, the
     * name of an active custom field, or a column of the failed rows file, ignoring case and surrounding spaces; one
     * that matches the name of a deleted custom field says so.
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
            [
                ...CustomField::forOrganization($this->organizationId)->onlyTrashed()->pluck('name')->all(),
                ...CompanyCustomField::forOrganization($this->organizationId)->onlyTrashed()->pluck('name')->map(fn (string $name): string => self::COMPANY_FIELD_PREFIX.$name)->all(),
            ],
        );

        return array_map(
            fn (string $header): string => in_array($this->headerKey($header), $deleted, true) ? "\"{$header}\" (deleted field)" : "\"{$header}\"",
            $ignored,
        );
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

        foreach ($this->companyCustomFields()->pluck('name')->all() as $name) {
            $column = self::COMPANY_FIELD_PREFIX.$name;

            $columns[$this->headerKey($column)] ??= $column;
        }

        foreach ([...self::BASE_COLUMNS, ...self::FAILED_ROWS_META_COLUMNS] as $column) {
            $columns[$column] = $column;
        }

        return $columns;
    }

    /**
     * The lowercase trimmed header, with the spaces around the colon of a `company: <field>` header normalized.
     */
    private function headerKey(string $header): string
    {
        $key = mb_strtolower(trim($header));

        return preg_replace('/^company\s*:\s*/u', self::COMPANY_FIELD_PREFIX, $key) ?? $key;
    }

    /**
     * Process a single CSV row.
     *
     * The contact is created without model events on purpose: segment membership comes from the full sync queued
     * when the import finishes. Any future Contact observer that should apply to imports must be called explicitly.
     *
     * The insert falls back to the existing row on a unique violation, so a contact created by someone else after
     * the existence check above is reported as a failed row instead of crashing the import. The value of a unique
     * custom field taken after the check above is reported the same way (see `EnforcesUniqueCustomFieldValues`).
     *
     * The company of the row (found or created, see `CompanyMatcher`), the contact, its tags and the link run in one
     * transaction: a row that ends up failing leaves no company behind. A created company is remembered and counted
     * once that transaction has committed.
     *
     * @param  array<string, string>  $row
     * @param  array<string, CustomField>  $customFieldsByName
     * @return array{success: bool, error: ?string}
     */
    public function processRow(array $row, array $customFieldsByName): array
    {
        // handles base attributes
        $name = trim($row['name'] ?? '');
        $email = mb_strtolower(trim($row['email'] ?? ''));

        if (blank($name)) {
            return ['success' => false, 'error' => 'Name is required.'];
        }

        if (blank($email)) {
            return ['success' => false, 'error' => 'Email is required.'];
        }

        // check email uniqueness
        $existingContact = Contact::withTrashed()
            ->where('organization_id', $this->organizationId)
            ->where('email', $email)
            ->first();

        if ($existingContact?->trashed()) {
            return ['success' => false, 'error' => "A deleted contact with email '{$email}' already exists. Restore it from the trash instead of importing it again."];
        }

        if ($existingContact) {
            return ['success' => false, 'error' => "A contact with email '{$email}' already exists."];
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => "Invalid email: {$email}"];
        }

        $phone = trim($row['phone'] ?? '') ?: null;

        $status = ContactStatus::Lead;
        $leadSource = null;

        foreach (['status' => ContactStatus::class, 'lead source' => LeadSource::class] as $column => $enumClass) {
            $raw = trim($row[$column] ?? '');

            if ($raw === '') {
                continue;
            }

            $value = $this->enumFromCell($enumClass, $raw);

            if ($value === null) {
                return ['success' => false, 'error' => "Invalid value for field '{$column}': {$raw}"];
            }

            if ($column === 'status') {
                $status = $value;
            } else {
                $leadSource = $value;
            }
        }

        // handle custom fields
        $customFieldValues = [];
        foreach ($customFieldsByName as $fieldName => $field) {
            // skip if the column doesn't exist in the CSV
            $rawValue = $row[$fieldName] ?? null;

            if (blank($rawValue) || ($field->type === 'multiselect' && $this->splitMultiValue($rawValue) === [])) {
                continue;
            }

            $value = $this->castFieldValue($field, $rawValue);

            if ($value === null) {
                return ['success' => false, 'error' => "Invalid value for field '{$fieldName}': {$rawValue}"];
            }

            if ($field->unique) {
                $exists = Contact::query()
                    ->where('organization_id', $this->organizationId)
                    ->whereCustomFieldValue($field->key, $value)
                    ->exists();

                if ($exists) {
                    return ['success' => false, 'error' => "Duplicate value for unique field '{$field->name}'."];
                }
            }

            $customFieldValues[$field->key] = $value;
        }

        $companyMatch = $this->matchCompany($row);

        if ($companyMatch?->isFailed()) {
            return ['success' => false, 'error' => $companyMatch->reason];
        }

        $companyDetails = null;

        if ($companyMatch?->isNotFound()) {
            $companyDetails = $this->companyDetailsFromRow($row);

            if ($companyDetails['error'] !== null) {
                return ['success' => false, 'error' => $companyDetails['error']];
            }
        }

        $tagIds = $this->resolveTagIds($row['tags'] ?? '');

        try {
            $createdCompany = DB::transaction(function () use ($companyMatch, $companyDetails, $row, $email, $name, $phone, $status, $leadSource, $customFieldValues, $tagIds): ?Company {
                $createdCompany = $companyMatch?->isNotFound() ? $this->createCompany($companyMatch, trim($row['company website'] ?? ''), $companyDetails) : null;
                $company = $createdCompany ?? $companyMatch?->company;

                $contact = Contact::withoutEvents(fn (): Contact => Contact::withTrashed()->createOrFirst(
                    ['organization_id' => $this->organizationId, 'email' => $email],
                    ['name' => $name, 'phone' => $phone, 'status' => $status, 'lead_source' => $leadSource, 'custom_field_values' => $customFieldValues],
                ));

                if (! $contact->wasRecentlyCreated) {
                    throw new ContactAlreadyExistsException($email);
                }

                $contact->tags()->sync($tagIds);

                if ($company !== null) {
                    $contact->companies()->attach($company->getKey());
                }

                return $createdCompany;
            });
        } catch (DuplicateCustomFieldValueException $exception) {
            return ['success' => false, 'error' => "Duplicate value for unique field '{$exception->fieldName}'."];
        } catch (ContactAlreadyExistsException $exception) {
            return ['success' => false, 'error' => $exception->getMessage()];
        }

        if ($createdCompany !== null) {
            $this->companyMatcher()->remember($createdCompany);
            $this->createdCompaniesCount++;
        }

        if ($companyMatch?->isFound() && $this->hasCompanyDetails($row)) {
            $this->companyDetailsIgnored = true;
        }

        return ['success' => true, 'error' => null];
    }

    /**
     * The company the row designates, or null when its company cells are blank (or the columns are missing).
     *
     * @param  array<string, string>  $row
     */
    private function matchCompany(array $row): ?CompanyMatch
    {
        $name = trim($row['company'] ?? '');
        $website = trim($row['company website'] ?? '');

        if ($name === '' && $website === '') {
            return null;
        }

        foreach (['company' => $name, 'company website' => $website] as $column => $value) {
            if (mb_strlen($value) > self::COMPANY_TEXT_MAX_LENGTH) {
                return CompanyMatch::failed("Invalid value for field '{$column}': {$value}");
            }
        }

        return $this->companyMatcher()->match($name, $website);
    }

    /**
     * @param  array{attributes: array<string, mixed>, address: ?array<string, string>, error: ?string}  $details
     */
    private function createCompany(CompanyMatch $match, string $website, array $details): Company
    {
        $address = $details['address'] === null
            ? null
            : Address::create(['organization_id' => $this->organizationId, ...$details['address']]);

        try {
            return Company::create([
                'organization_id' => $this->organizationId,
                'name' => $match->name ?? $match->domain,
                'website' => $website,
                'address_id' => $address?->getKey(),
                ...$details['attributes'],
            ]);
        } catch (DuplicateCustomFieldValueException $exception) {
            throw new DuplicateCustomFieldValueException(self::COMPANY_FIELD_PREFIX.$exception->fieldName, $exception->fieldKey);
        }
    }

    /**
     * The values a new company gets from the company columns of the row, or the error that fails the row. Only used
     * when the row creates the company, so a row linking an existing company never fails on these columns.
     *
     * @param  array<string, string>  $row
     * @return array{attributes: array<string, mixed>, address: ?array<string, string>, error: ?string}
     */
    private function companyDetailsFromRow(array $row): array
    {
        $attributes = ['custom_field_values' => []];
        $address = [];

        foreach (self::COMPANY_DETAIL_COLUMNS as $column) {
            $raw = trim($row[$column] ?? '');

            if ($raw === '') {
                continue;
            }

            $value = $this->companyColumnValue($column, $raw);

            if ($value === null) {
                return $this->companyDetailsError("Invalid value for field '{$column}': {$raw}");
            }

            match ($column) {
                'company type' => $attributes['company_type_id'] = $value,
                'company phone' => $attributes['phone'] = $value,
                'company industry' => $attributes['industry'] = $value,
                'company employees' => $attributes['employees'] = $value,
                'company annual revenue' => $attributes['annual_revenue'] = $value,
                default => $address[substr($column, strlen('company '))] = $value,
            };
        }

        foreach ($this->companyCustomFields() as $field) {
            $column = self::COMPANY_FIELD_PREFIX.$field->name;
            $rawValue = $row[$column] ?? null;

            if (blank($rawValue) || ($field->type === 'multiselect' && $this->splitMultiValue($rawValue) === [])) {
                continue;
            }

            $value = $this->castFieldValue($field, $rawValue);

            if ($value === null) {
                return $this->companyDetailsError("Invalid value for field '{$column}': {$rawValue}");
            }

            if ($field->unique && Company::forOrganization($this->organizationId)->whereCustomFieldValue($field->key, $value)->exists()) {
                return $this->companyDetailsError("Duplicate value for unique field '{$column}'.");
            }

            $attributes['custom_field_values'][$field->key] = $value;
        }

        return ['attributes' => $attributes, 'address' => $address === [] ? null : $address, 'error' => null];
    }

    /**
     * @return array{attributes: array<string, mixed>, address: null, error: string}
     */
    private function companyDetailsError(string $error): array
    {
        return ['attributes' => [], 'address' => null, 'error' => $error];
    }

    /**
     * The value a built-in company column designates, or null when the cell is not valid.
     */
    private function companyColumnValue(string $column, string $raw): mixed
    {
        return match ($column) {
            'company type' => $this->companyTypeIds()[mb_strtolower($raw)] ?? null,
            'company phone' => mb_strlen($raw) <= 50 ? $raw : null,
            'company industry' => $this->enumFromCell(CompanyIndustry::class, $raw),
            'company employees' => $this->parseEmployees($raw),
            'company annual revenue' => $this->parseAnnualRevenue($raw),
            default => mb_strlen($raw) <= 255 ? $raw : null,
        };
    }

    private function parseEmployees(string $raw): ?int
    {
        if (preg_match('/^\d{1,10}$/', $raw) !== 1 || (int) $raw > self::MAX_EMPLOYEES) {
            return null;
        }

        return (int) $raw;
    }

    private function parseAnnualRevenue(string $raw): ?string
    {
        $number = $this->parseNumber($raw);

        if ($number === null || $number < 0 || $number > self::MAX_ANNUAL_REVENUE) {
            return null;
        }

        return number_format($number, 2, '.', '');
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

    /**
     * Whether any company column besides the ones naming the company is filled in the row.
     *
     * @param  array<string, string>  $row
     */
    private function hasCompanyDetails(array $row): bool
    {
        $columns = [
            ...self::COMPANY_DETAIL_COLUMNS,
            ...$this->companyCustomFields()->map(fn (CompanyCustomField $field): string => self::COMPANY_FIELD_PREFIX.$field->name)->all(),
        ];

        foreach ($columns as $column) {
            if (trim((string) ($row[$column] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function companyMatcher(): CompanyMatcher
    {
        return $this->companyMatcher ??= CompanyMatcher::forOrganization($this->organizationId);
    }

    /**
     * The case of an enum a cell designates: first its stored value (`active_client`), then its label ignoring case and
     * surrounding spaces (`Active Client`).
     *
     * @param  class-string<BackedEnum&HasLabel>  $enumClass
     */
    private function enumFromCell(string $enumClass, string $raw): ?BackedEnum
    {
        $raw = trim($raw);

        $byValue = $enumClass::tryFrom($raw);

        if ($byValue !== null) {
            return $byValue;
        }

        foreach ($enumClass::cases() as $case) {
            if (mb_strtolower(trim((string) $case->getLabel())) === mb_strtolower($raw)) {
                return $case;
            }
        }

        return null;
    }

    /**
     * Resolve the tag names of an imported row, creating missing tags and restoring trashed ones. `firstOrCreate()`
     * already falls back to the existing row when someone else creates the tag in the meantime.
     *
     * @return array<int, int>
     */
    public function resolveTagIds(string $raw): array
    {
        if (blank($raw)) {
            return [];
        }

        $tagNames = array_filter(array_map('trim', explode(self::MULTI_VALUE_SEPARATOR, $raw)));
        $ids = [];

        foreach ($tagNames as $tagName) {
            $tag = Tag::withTrashed()->firstOrCreate(
                ['organization_id' => $this->organizationId, 'name' => $tagName]
            );

            if ($tag->trashed()) {
                $tag->restore();
            }

            $ids[] = $tag->id;
        }

        return $ids;
    }

    private function castFieldValue(CustomField|CompanyCustomField $field, string $raw): mixed
    {
        return match ($field->type) {
            'email' => filter_var($raw, FILTER_VALIDATE_EMAIL) ? $raw : null,
            'url' => filter_var($raw, FILTER_VALIDATE_URL) ? $raw : null,
            'phone' => $this->parsePhone($raw),
            'number' => $this->parseNumber($raw),
            'date' => $this->formatDate($raw),
            'multiselect' => $this->parseMultiselectValue($field, $raw),
            'select' => $this->parseSelectValue($field, trim($raw)),
            default => trim($raw),
        };
    }

    private function parseNumber(string $raw): ?float
    {
        if ($this->dialect?->isEuropean() && preg_match('/^-?\d+,\d+$/', $raw) === 1) {
            $raw = str_replace(',', '.', $raw);
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    private function formatDate(string $raw): ?string
    {
        $date = $this->parseDate($raw);

        return $date === false ? null : $date->format('Y-m-d');
    }

    private function parsePhone(string $raw): ?string
    {
        $phone = preg_replace('/[^\d+]/', '', $raw);

        return preg_match('/\d/', $phone) === 1 ? $phone : null;
    }

    /**
     * Accepts "d-m-Y H:i:s", "d-m-Y" and ISO "Y-m-d", and "d/m/Y" and "d.m.Y" in a European Excel file ("03/04/2026" is
     * ambiguous in other files, so it stays refused there). Each format must round-trip exactly, which is what rejects
     * impossible dates such as "31-02-2024".
     */
    public function parseDate(string $raw): DateTimeInterface|false
    {
        $raw = trim($raw);

        $formats = ['d-m-Y H:i:s', self::DATE_FORMAT, 'Y-m-d', ...($this->dialect?->isEuropean() ? ['d/m/Y', 'd.m.Y'] : [])];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $raw);

            if ($date !== false && $date->format($format) === $raw) {
                return $date;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>|null A list of allowed values, or null when a value is not allowed or there is none.
     */
    public function parseMultiselectValue(CustomField|CompanyCustomField $field, string $raw): ?array
    {
        $parts = $this->splitMultiValue($raw);

        if ($parts === []) {
            return null;
        }

        $values = [];

        foreach ($parts as $part) {
            $value = $this->optionValueFor($field, $part);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return array_values(array_unique($values));
    }

    /**
     * The non-empty, trimmed parts of a multi-value cell, as a list (a stored JSON array, never an object).
     *
     * @return array<int, string>
     */
    private function splitMultiValue(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode(self::MULTI_VALUE_SEPARATOR, $raw)),
            fn (string $value): bool => $value !== '',
        ));
    }

    public function parseSelectValue(CustomField|CompanyCustomField $field, string $value): ?string
    {
        return $this->optionValueFor($field, $value);
    }

    /**
     * The stored value of the option a cell part designates: first an exact stored value (old files and failed-rows
     * files keep working), otherwise the option with that label, ignoring case and surrounding spaces.
     */
    private function optionValueFor(CustomField|CompanyCustomField $field, string $part): ?string
    {
        $options = collect($field->options ?? []);
        $part = trim($part);

        $byValue = $options->first(fn (array $option): bool => (string) ($option['value'] ?? '') === $part);

        if ($byValue !== null) {
            return (string) $byValue['value'];
        }

        $byLabel = $options->first(fn (array $option): bool => mb_strtolower(trim((string) ($option['label'] ?? ''))) === mb_strtolower($part));

        return $byLabel === null ? null : (string) $byLabel['value'];
    }

    /**
     * A value for the import template that the import accepts for the field, as it casts it.
     */
    public function exampleValueFor(CustomField|CompanyCustomField $field): string
    {
        $optionLabels = collect($field->options ?? [])->pluck('label')->map(fn ($label): string => (string) $label);

        return match ($field->type) {
            'textarea' => 'Some notes',
            'email' => 'jane@example.com',
            'url' => 'https://example.com',
            'phone' => '+33612345678',
            'number' => '42',
            'date' => now()->startOfYear()->addDays(14)->format(self::DATE_FORMAT),
            'select' => $optionLabels->first() ?? '',
            'multiselect' => $optionLabels
                ->reject(fn (string $label): bool => str_contains($label, self::MULTI_VALUE_SEPARATOR))
                ->take(2)
                ->implode(self::MULTI_VALUE_SEPARATOR),
            default => 'Some text',
        };
    }
}
