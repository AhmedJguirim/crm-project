<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Models\Deal;
use App\Services\Exports\ExportCells;
use App\Services\Exports\ExportFileWriter;
use App\Services\Imports\ImportCellParser;
use App\Support\LocalTime;

/**
 * Writes deals to a spreadsheet: ids and related ids with readable names, timestamps in the organization timezone
 * (named in their header). Formula safety and number cells are those of {@see ExportCells}.
 */
class DealExportWriter
{
    /** The columns in file order; the four timestamp columns get the timezone appended to their header. */
    public const COLUMNS = [
        'id',
        'title',
        'value',
        'currency',
        'stage',
        'status',
        'expected close date',
        'won at',
        'lost at',
        'lost reason',
        'lost reason note',
        'owner',
        'contact id',
        'contact',
        'contact email',
        'company id',
        'company',
        'notes',
        'created at',
        'updated at',
    ];

    private const TIMESTAMP_COLUMNS = ['won at', 'lost at', 'created at', 'updated at'];

    private const TIMESTAMP_FORMAT = 'd-m-Y H:i';

    /**
     * @param  string  $timezone  A valid timezone identifier: the timezone of the organization.
     * @param  string  $currency  The ISO code of the currency of the organization, written on every row.
     */
    public function __construct(private readonly string $timezone, private readonly string $currency) {}

    /** @return array<int, string> */
    public function headers(ExportFormat $format = ExportFormat::Xlsx): array
    {
        return ExportCells::headers(
            array_map(
                fn (string $column): string => in_array($column, self::TIMESTAMP_COLUMNS, true) ? "{$column} ({$this->timezone})" : $column,
                self::COLUMNS,
            ),
            $format,
        );
    }

    /**
     * The cells of one deal, in the order of `headers()`. The deal needs its `pipelineStage`, `lostReason`, `owner`,
     * `contact` (deleted ones too) and `companyWithTrashed` relations loaded.
     *
     * @return array<int, string|int|float>
     */
    public function row(Deal $deal, ExportFormat $format): array
    {
        return ExportCells::row([
            (int) $deal->id,
            (string) $deal->title,
            ExportCells::money($deal->value, $format),
            $this->currency,
            (string) $deal->pipelineStage?->name,
            (string) $deal->status?->getLabel(),
            (string) $deal->expected_close_date?->format(ImportCellParser::DATE_FORMAT),
            (string) LocalTime::format($deal->won_at, self::TIMESTAMP_FORMAT, $this->timezone),
            (string) LocalTime::format($deal->lost_at, self::TIMESTAMP_FORMAT, $this->timezone),
            (string) $deal->lostReason?->name,
            (string) $deal->lost_reason_note,
            (string) $deal->owner?->email,
            $deal->contact_id === null ? '' : (int) $deal->contact_id,
            (string) $deal->contact?->name,
            (string) $deal->contact?->email,
            $deal->company_id === null ? '' : (int) $deal->company_id,
            (string) $deal->companyWithTrashed?->name,
            (string) $deal->notes,
            (string) LocalTime::format($deal->created_at, self::TIMESTAMP_FORMAT, $this->timezone),
            (string) LocalTime::format($deal->updated_at, self::TIMESTAMP_FORMAT, $this->timezone),
        ], $format);
    }

    /**
     * Writes the header and the deals of every chunk to the file; the type comes from its extension.
     *
     * @param  iterable<int, iterable<int, Deal>>  $chunks  Deals with the relations `row()` needs.
     * @return int The number of deals written.
     */
    public function write(string $path, ExportFormat $format, iterable $chunks): int
    {
        return ExportFileWriter::write(
            $path,
            $this->headers($format),
            $chunks,
            fn (Deal $deal): array => $this->row($deal, $format),
        );
    }
}
