<?php

namespace App\Services;

use App\Enums\CompanyIndustry;
use App\Enums\ContactStatus;
use App\Enums\ImportMode;
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
use App\Services\Imports\ImportCellParser;
use App\Support\CsvDialect;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContactImportService
{
    /** Separates several tags, or several values of a multi-select field, in one cell. */
    public const MULTI_VALUE_SEPARATOR = ImportCellParser::MULTI_VALUE_SEPARATOR;

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

    /** The length of the `contacts.name`, `contacts.phone` and `tags.name` columns. */
    private const TEXT_MAX_LENGTH = 255;

    /** The record id column of an exported file; read by the update modes of the import. */
    public const ID_COLUMN = 'id';

    /** The columns the failed rows file adds in front of the original ones, so a corrected file can be imported again. */
    public const FAILED_ROWS_META_COLUMNS = ['_row_number', '_error'];

    /**
     * The columns whose cell can't be `-`: a contact can't be left without them, and tags and companies are only added.
     */
    private const NOT_CLEARABLE_COLUMNS = ['name', 'email', 'status', 'tags', 'company', 'company website'];

    /** The date format shown to users; ISO (`Y-m-d`) and a trailing time are accepted too. */
    public const DATE_FORMAT = ImportCellParser::DATE_FORMAT;

    /**
     * @param  CsvDialect|null  $dialect  How the file is written. A file with `;` between columns is a European Excel
     *                                    file, so its numbers may have a decimal comma and its dates may be d/m/Y or d.m.Y.
     */
    public function __construct(
        private readonly int $organizationId,
        ?CsvDialect $dialect = null,
        private readonly ImportMode $mode = ImportMode::CreateOnly,
    ) {
        $this->parser = new ImportCellParser($organizationId, $dialect);
    }

    private ImportCellParser $parser;

    private ?CompanyMatcher $companyMatcher = null;

    private int $createdCompaniesCount = 0;

    private int $updatedContactsCount = 0;

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
     * How many existing contacts the rows processed so far updated (only counted once their row was committed).
     */
    public function updatedContactsCount(): int
    {
        return $this->updatedContactsCount;
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

        foreach ([...self::BASE_COLUMNS, ...self::FAILED_ROWS_META_COLUMNS, self::ID_COLUMN] as $column) {
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
     * Process a single CSV row: in an update mode the row is matched to an existing contact (see `updateOrCreate()`),
     * otherwise it creates one.
     *
     * @param  array<string, string>  $row
     * @param  array<string, CustomField>  $customFieldsByName
     * @return array{success: bool, error: ?string}
     */
    public function processRow(array $row, array $customFieldsByName): array
    {
        if (! $this->mode->updatesExisting()) {
            return $this->createContact($row, $customFieldsByName);
        }

        return $this->updateOrCreate($row, $customFieldsByName);
    }

    /**
     * Create the contact of a single CSV row.
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
    private function createContact(array $row, array $customFieldsByName): array
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

        $tooLong = $this->textTooLongError('name', $name) ?? $this->textTooLongError('phone', (string) $phone);

        if ($tooLong !== null) {
            return ['success' => false, 'error' => $tooLong];
        }

        $status = ContactStatus::Lead;
        $leadSource = null;

        foreach (['status' => ContactStatus::class, 'lead source' => LeadSource::class] as $column => $enumClass) {
            $raw = trim($row[$column] ?? '');

            if ($raw === '') {
                continue;
            }

            $value = $this->parser->enumFromCell($enumClass, $raw);

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

            if (blank($rawValue) || ($field->type === 'multiselect' && $this->parser->splitMultiValue($rawValue) === [])) {
                continue;
            }

            $value = $this->parser->castFieldValue($field, $rawValue);

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

        $tagError = $this->tagNameError($row['tags'] ?? '');

        if ($tagError !== null) {
            return ['success' => false, 'error' => $tagError];
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

        $this->recordCompanyOutcome($createdCompany, $companyMatch, $row);

        return ['success' => true, 'error' => null];
    }

    /**
     * The bookkeeping of a committed row: a created company is remembered and counted, and the company details of a row
     * that linked an existing company are reported as left alone.
     *
     * @param  array<string, string>  $row
     */
    private function recordCompanyOutcome(?Company $createdCompany, ?CompanyMatch $companyMatch, array $row): void
    {
        if ($createdCompany !== null) {
            $this->companyMatcher()->remember($createdCompany);
            $this->createdCompaniesCount++;
        }

        if ($companyMatch?->isFound() && $this->hasCompanyDetails($row)) {
            $this->companyDetailsIgnored = true;
        }
    }

    /**
     * An update mode: finds the contact of the row by its `id` cell, else by its email, then updates it. A row that
     * matches no contact is created in "create and update" and fails in "update only"; an id that matches no contact
     * always fails.
     *
     * @param  array<string, string>  $row
     * @param  array<string, CustomField>  $customFieldsByName
     * @return array{success: bool, error: ?string}
     */
    private function updateOrCreate(array $row, array $customFieldsByName): array
    {
        foreach (self::NOT_CLEARABLE_COLUMNS as $column) {
            if (trim((string) ($row[$column] ?? '')) === '-') {
                return $this->failure("The '{$column}' column can't be cleared.");
            }
        }

        $id = trim((string) ($row[self::ID_COLUMN] ?? ''));

        if ($id !== '') {
            return $this->updateById($id, $row, $customFieldsByName);
        }

        $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

        if ($email === '') {
            return $this->failure('Email is required.');
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->failure("Invalid email: {$email}");
        }

        $contact = Contact::withTrashed()
            ->where('organization_id', $this->organizationId)
            ->where('email', $email)
            ->first();

        if ($contact === null) {
            return $this->mode->createsNew()
                ? $this->createContact($row, $customFieldsByName)
                : $this->failure("No contact with email '{$email}'.");
        }

        if ($contact->trashed()) {
            return $this->failure("A deleted contact with email '{$email}' exists. Restore it from the trash first.");
        }

        return $this->updateContact($contact, $row, $customFieldsByName);
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, CustomField>  $customFieldsByName
     * @return array{success: bool, error: ?string}
     */
    private function updateById(string $id, array $row, array $customFieldsByName): array
    {
        if (preg_match('/^\d+$/', $id) !== 1) {
            return $this->failure("Invalid value for field 'id': {$id}");
        }

        $contact = Contact::withTrashed()
            ->where('organization_id', $this->organizationId)
            ->whereKey((int) $id)
            ->first();

        if ($contact === null) {
            return $this->failure("No contact with id {$id}.");
        }

        if ($contact->trashed()) {
            return $this->failure("The contact with id {$id} is deleted. Restore it from the trash first.");
        }

        return $this->updateContact($contact, $row, $customFieldsByName);
    }

    /**
     * Update the contact with the non-blank cells of the row, or fail the row and leave the contact untouched: every
     * check runs before the transaction. A blank cell keeps the value, `-` clears it where that is allowed, tags are
     * only added and an existing company is linked, never changed.
     *
     * Like the creation, it saves without model events: the segments are synced when the import finishes.
     *
     * @param  array<string, string>  $row
     * @param  array<string, CustomField>  $customFieldsByName
     * @return array{success: bool, error: ?string}
     */
    private function updateContact(Contact $contact, array $row, array $customFieldsByName): array
    {
        $base = $this->updatedBaseAttributes($contact, $row);

        if ($base['error'] !== null) {
            return $this->failure($base['error']);
        }

        $custom = $this->updatedCustomFieldValues($contact, $row, $customFieldsByName);

        if ($custom['error'] !== null) {
            return $this->failure($custom['error']);
        }

        $companyMatch = $this->matchCompany($row);

        if ($companyMatch?->isFailed()) {
            return $this->failure((string) $companyMatch->reason);
        }

        $companyDetails = null;

        if ($companyMatch?->isNotFound()) {
            $companyDetails = $this->companyDetailsFromRow($row);

            if ($companyDetails['error'] !== null) {
                return $this->failure($companyDetails['error']);
            }
        }

        $attributes = $base['attributes'];

        if ($custom['values'] !== ($contact->custom_field_values ?? [])) {
            $attributes['custom_field_values'] = $custom['values'];
        }

        $tagError = $this->tagNameError($row['tags'] ?? '');

        if ($tagError !== null) {
            return $this->failure($tagError);
        }

        $tagIds = $this->resolveTagIds($row['tags'] ?? '');
        $email = $attributes['email'] ?? $contact->email;

        try {
            $createdCompany = DB::transaction(function () use ($contact, $attributes, $companyMatch, $companyDetails, $row, $tagIds): ?Company {
                $createdCompany = $companyMatch?->isNotFound() ? $this->createCompany($companyMatch, trim($row['company website'] ?? ''), $companyDetails) : null;
                $company = $createdCompany ?? $companyMatch?->company;

                Contact::withoutEvents(fn (): bool => $contact->fill($attributes)->save());

                $contact->tags()->syncWithoutDetaching($tagIds);

                if ($company !== null) {
                    $contact->companies()->syncWithoutDetaching([$company->getKey()]);
                }

                return $createdCompany;
            });
        } catch (DuplicateCustomFieldValueException $exception) {
            return $this->failure("Duplicate value for unique field '{$exception->fieldName}'.");
        } catch (UniqueConstraintViolationException) {
            return $this->failure("A contact with email '{$email}' already exists.");
        }

        $this->recordCompanyOutcome($createdCompany, $companyMatch, $row);
        $this->updatedContactsCount++;

        return ['success' => true, 'error' => null];
    }

    /**
     * The new values of name, email, phone, status and lead source: only the cells that are filled, `-` clearing the
     * phone and the lead source.
     *
     * @param  array<string, string>  $row
     * @return array{attributes: array<string, mixed>, error: ?string}
     */
    private function updatedBaseAttributes(Contact $contact, array $row): array
    {
        $attributes = [];

        $name = trim((string) ($row['name'] ?? ''));
        $nameError = $this->textTooLongError('name', $name);

        if ($nameError !== null) {
            return ['attributes' => [], 'error' => $nameError];
        }

        if ($name !== '') {
            $attributes['name'] = $name;
        }

        $email = mb_strtolower(trim((string) ($row['email'] ?? '')));

        if ($email !== '' && $email !== mb_strtolower((string) $contact->email)) {
            $error = $this->emailChangeError($contact, $email);

            if ($error !== null) {
                return ['attributes' => [], 'error' => $error];
            }

            $attributes['email'] = $email;
        }

        $phone = trim((string) ($row['phone'] ?? ''));
        $phoneError = $this->textTooLongError('phone', $phone);

        if ($phoneError !== null) {
            return ['attributes' => [], 'error' => $phoneError];
        }

        if ($phone !== '') {
            $attributes['phone'] = $phone === '-' ? null : $phone;
        }

        foreach (['status' => ContactStatus::class, 'lead source' => LeadSource::class] as $column => $enumClass) {
            $raw = trim((string) ($row[$column] ?? ''));

            if ($raw === '') {
                continue;
            }

            if ($column === 'lead source' && $raw === '-') {
                $attributes['lead_source'] = null;

                continue;
            }

            $value = $this->parser->enumFromCell($enumClass, $raw);

            if ($value === null) {
                return ['attributes' => [], 'error' => "Invalid value for field '{$column}': {$raw}"];
            }

            $attributes[$column === 'status' ? 'status' : 'lead_source'] = $value;
        }

        return ['attributes' => $attributes, 'error' => null];
    }

    /**
     * Why the contact can't get this other email: it is not an address, or another contact (a deleted one too) has it.
     */
    private function emailChangeError(Contact $contact, string $email): ?string
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return "Invalid email: {$email}";
        }

        $taken = Contact::withTrashed()
            ->where('organization_id', $this->organizationId)
            ->where('email', $email)
            ->whereKeyNot($contact->getKey())
            ->exists();

        return $taken ? "A contact with email '{$email}' already exists." : null;
    }

    /**
     * The custom field values the contact has after the row: the stored ones, with the filled cells replacing them and
     * `-` removing them. A unique value taken by another contact is an error; the contact's own value is not.
     *
     * @param  array<string, string>  $row
     * @param  array<string, CustomField>  $customFieldsByName
     * @return array{values: array<string, mixed>, error: ?string}
     */
    private function updatedCustomFieldValues(Contact $contact, array $row, array $customFieldsByName): array
    {
        $values = $contact->custom_field_values ?? [];

        foreach ($customFieldsByName as $fieldName => $field) {
            $rawValue = $row[$fieldName] ?? null;

            if (blank($rawValue) || ($field->type === 'multiselect' && $this->parser->splitMultiValue($rawValue) === [])) {
                continue;
            }

            if (trim($rawValue) === '-') {
                unset($values[$field->key]);

                continue;
            }

            $value = $this->parser->castFieldValue($field, $rawValue);

            if ($value === null) {
                return ['values' => [], 'error' => "Invalid value for field '{$fieldName}': {$rawValue}"];
            }

            if ($field->unique && $this->customFieldValueTakenByAnother($contact, $field, $value)) {
                return ['values' => [], 'error' => "Duplicate value for unique field '{$field->name}'."];
            }

            $values[$field->key] = $value;
        }

        return ['values' => $values, 'error' => null];
    }

    private function customFieldValueTakenByAnother(Contact $contact, CustomField $field, mixed $value): bool
    {
        return Contact::query()
            ->where('organization_id', $this->organizationId)
            ->whereKeyNot($contact->getKey())
            ->whereCustomFieldValue($field->key, $value)
            ->exists();
    }

    /**
     * The row error for a value longer than its column, or null. The value is shortened: the failed rows file keeps it whole.
     */
    private function textTooLongError(string $column, string $value): ?string
    {
        if (mb_strlen($value) <= self::TEXT_MAX_LENGTH) {
            return null;
        }

        return "Invalid value for field '{$column}': ".Str::limit($value, 50);
    }

    /**
     * The row error for the first tag name of the cell longer than `tags.name`, or null. Splits like resolveTagIds().
     */
    private function tagNameError(string $raw): ?string
    {
        foreach (array_filter(array_map('trim', explode(self::MULTI_VALUE_SEPARATOR, $raw))) as $tagName) {
            $error = $this->textTooLongError('tags', $tagName);

            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @return array{success: false, error: string}
     */
    private function failure(string $error): array
    {
        return ['success' => false, 'error' => $error];
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

            if (blank($rawValue) || ($field->type === 'multiselect' && $this->parser->splitMultiValue($rawValue) === [])) {
                continue;
            }

            $value = $this->parser->castFieldValue($field, $rawValue);

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
            'company industry' => $this->parser->enumFromCell(CompanyIndustry::class, $raw),
            'company employees' => $this->parser->parseEmployees($raw),
            'company annual revenue' => $this->parser->parseAnnualRevenue($raw),
            default => mb_strlen($raw) <= 255 ? $raw : null,
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

    /**
     * @return array<int, string>|null
     *
     * @see ImportCellParser::parseMultiselectValue()
     */
    public function parseMultiselectValue(CustomField|CompanyCustomField $field, string $raw): ?array
    {
        return $this->parser->parseMultiselectValue($field, $raw);
    }

    public function parseSelectValue(CustomField|CompanyCustomField $field, string $value): ?string
    {
        return $this->parser->parseSelectValue($field, $value);
    }

    public function parseDate(string $raw): DateTimeInterface|false
    {
        return $this->parser->parseDate($raw);
    }

    public function exampleValueFor(CustomField|CompanyCustomField $field): string
    {
        return $this->parser->exampleValueFor($field);
    }
}
