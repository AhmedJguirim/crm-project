<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Services\Exports\ExportCells;
use App\Services\Exports\ExportFileWriter;
use Illuminate\Support\Collection;

/**
 * Writes companies to a spreadsheet with the column names the companies import reads, so the file can be imported
 * again. The values are the ones a person reads: the name of the type, the label of the industry, dates as dd-mm-yyyy.
 *
 * Formula safety and number cells are those of {@see ExportCells}.
 */
class CompanyExportWriter
{
    /** The columns that are not custom fields. `id` is the record id; `contacts` is for people and the import ignores it. */
    public const COLUMNS = ['id', ...CompanyImportService::COLUMNS, 'contacts'];

    /**
     * @param  Collection<int, CompanyCustomField>  $customFields  The active company custom fields, in column order.
     */
    public function __construct(private readonly Collection $customFields) {}

    /** @return array<int, string> */
    public function headers(ExportFormat $format = ExportFormat::Xlsx): array
    {
        return ExportCells::headers([
            ...self::COLUMNS,
            ...$this->customFields->map(fn (CompanyCustomField $field): string => $field->name)->all(),
        ], $format);
    }

    /**
     * The cells of one company, in the order of `headers()`. The company needs its `address` and
     * `companyTypeWithTrashed` relations and its `contacts_count` loaded.
     *
     * @return array<int, string|int|float>
     */
    public function row(Company $company, ExportFormat $format): array
    {
        return ExportCells::row([
            (int) $company->id,
            (string) $company->name,
            (string) $company->website,
            (string) $company->companyTypeWithTrashed?->name,
            (string) $company->phone,
            (string) $company->industry?->getLabel(),
            $company->employees === null ? '' : ExportCells::number($company->employees, $format),
            $company->annual_revenue === null ? '' : ExportCells::number($company->annual_revenue, $format),
            (string) $company->address?->street,
            (string) $company->address?->city,
            (string) $company->address?->zip,
            (string) $company->address?->country,
            (string) $company->notes,
            (int) $company->contacts_count,
            ...$this->customFields
                ->map(fn (CompanyCustomField $field): string|int|float => ExportCells::customField($company, $field, $format))
                ->all(),
        ], $format);
    }

    /**
     * Writes the header and the companies of every chunk to the file; the type comes from its extension.
     *
     * @param  iterable<int, iterable<int, Company>>  $chunks  Companies with the relations `row()` needs.
     * @return int The number of companies written.
     */
    public function write(string $path, ExportFormat $format, iterable $chunks): int
    {
        return ExportFileWriter::write(
            $path,
            $this->headers($format),
            $chunks,
            fn (Company $company): array => $this->row($company, $format),
        );
    }
}
