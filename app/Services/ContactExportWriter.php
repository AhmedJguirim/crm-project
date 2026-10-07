<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomField;
use App\Services\Exports\ExportCells;
use App\Services\Exports\ExportFileWriter;
use Illuminate\Support\Collection;

/**
 * Writes contacts to a spreadsheet with the column names the contacts import reads, so the file can be imported again.
 *
 * The values are the ones a person reads: labels for the enums and the options of select fields, dates as dd-mm-yyyy.
 * In a CSV every cell is text; in an .xlsx the number custom fields are numeric cells and everything else is an
 * explicit text cell, so a value starting with `=` is never turned into a formula.
 *
 * A spreadsheet app evaluates a CSV cell that starts with `=`, `@`, a tab or a carriage return, or with `+` or `-`
 * followed by anything but digits and number punctuation. Those cells get a leading `'`, header cells included;
 * phone numbers (`+33 1`) and negative numbers are left as stored. Importing such a cell again gives the value with
 * the leading `'`.
 */
class ContactExportWriter
{
    /** The columns that are not custom fields. `all companies` is for people; the import ignores it. */
    public const COLUMNS = ['name', 'email', 'phone', 'status', 'lead source', 'tags', 'company', 'company website', 'all companies'];

    /**
     * @param  Collection<int, CustomField>  $customFields  The active custom fields, in column order.
     */
    public function __construct(private readonly Collection $customFields) {}

    /** @return array<int, string> */
    public function headers(ExportFormat $format = ExportFormat::Xlsx): array
    {
        return ExportCells::headers([
            ...self::COLUMNS,
            ...$this->customFields->map(fn (CustomField $field): string => $field->name)->all(),
        ], $format);
    }

    /**
     * The text a CSV cell gets: the value, with a leading `'` when a spreadsheet app would evaluate it as a formula.
     */
    public static function csvSafe(string $value): string
    {
        return ExportCells::csvSafe($value);
    }

    /**
     * The cells of one contact, in the order of `headers()`. The contact needs its `tags` and `companies` loaded.
     *
     * @return array<int, string|int|float>
     */
    public function row(Contact $contact, ExportFormat $format): array
    {
        $companies = $contact->companies
            ->sortBy(fn (Company $company): string => mb_strtolower($company->name))
            ->values();

        return ExportCells::row([
            (string) $contact->name,
            (string) $contact->email,
            (string) $contact->phone,
            (string) $contact->status?->getLabel(),
            (string) $contact->lead_source?->getLabel(),
            $contact->tags->pluck('name')->sortBy(fn (string $name): string => mb_strtolower($name))->implode(';'),
            (string) $companies->first()?->name,
            (string) $companies->first()?->website,
            $companies->count() >= 2 ? $companies->pluck('name')->implode('; ') : '',
            ...$this->customFields
                ->map(fn (CustomField $field): string|int|float => ExportCells::customField($contact, $field, $format))
                ->all(),
        ], $format);
    }

    /**
     * Writes the header and the contacts of every chunk to the file; the type comes from its extension.
     *
     * @param  iterable<int, iterable<int, Contact>>  $chunks  Contacts with their `tags` and `companies` loaded.
     * @return int The number of contacts written.
     */
    public function write(string $path, ExportFormat $format, iterable $chunks): int
    {
        return ExportFileWriter::write(
            $path,
            $this->headers($format),
            $chunks,
            fn (Contact $contact): array => $this->row($contact, $format),
        );
    }
}
