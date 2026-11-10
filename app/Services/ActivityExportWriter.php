<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Models\Activity;
use App\Services\Exports\ExportCells;
use App\Services\Exports\ExportFileWriter;
use App\Support\LocalTime;

/**
 * Writes activities to a spreadsheet: ids and related ids with readable names, timestamps in the organization timezone
 * (named in their header). Formula safety is that of {@see ExportCells}.
 */
class ActivityExportWriter
{
    /** The columns in file order; the three timestamp columns get the timezone appended to their header. */
    public const COLUMNS = [
        'id',
        'type',
        'occurred at',
        'duration (minutes)',
        'subject',
        'outcome',
        'notes',
        'logged by',
        'contact id',
        'contact',
        'contact email',
        'deal id',
        'deal',
        'company id',
        'company',
        'created at',
        'updated at',
    ];

    private const TIMESTAMP_COLUMNS = ['occurred at', 'created at', 'updated at'];

    private const TIMESTAMP_FORMAT = 'd-m-Y H:i';

    /**
     * @param  string  $timezone  A valid timezone identifier: the timezone of the organization.
     */
    public function __construct(private readonly string $timezone) {}

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
     * The cells of one activity, in the order of `headers()`. The activity needs its `user`, `contact` (deleted ones
     * too), `deal` and `company` (deleted ones too) relations loaded.
     *
     * @return array<int, string|int|float>
     */
    public function row(Activity $activity, ExportFormat $format): array
    {
        return ExportCells::row([
            (int) $activity->id,
            (string) $activity->type?->getLabel(),
            (string) LocalTime::format($activity->occurred_at, self::TIMESTAMP_FORMAT, $this->timezone),
            $activity->duration_minutes === null ? '' : (int) $activity->duration_minutes,
            (string) $activity->subject,
            (string) $activity->outcome?->getLabel(),
            (string) $activity->notes,
            (string) $activity->user?->email,
            $activity->contact_id === null ? '' : (int) $activity->contact_id,
            (string) $activity->contact?->name,
            (string) $activity->contact?->email,
            $activity->deal === null ? '' : (int) $activity->deal_id,
            (string) $activity->deal?->title,
            $activity->company_id === null ? '' : (int) $activity->company_id,
            (string) $activity->company?->name,
            (string) LocalTime::format($activity->created_at, self::TIMESTAMP_FORMAT, $this->timezone),
            (string) LocalTime::format($activity->updated_at, self::TIMESTAMP_FORMAT, $this->timezone),
        ], $format);
    }

    /**
     * Writes the header and the activities of every chunk to the file; the type comes from its extension.
     *
     * @param  iterable<int, iterable<int, Activity>>  $chunks  Activities with the relations `row()` needs.
     * @return int The number of activities written.
     */
    public function write(string $path, ExportFormat $format, iterable $chunks): int
    {
        return ExportFileWriter::write(
            $path,
            $this->headers($format),
            $chunks,
            fn (Activity $activity): array => $this->row($activity, $format),
        );
    }
}
