<?php

namespace App\Services;

use App\Enums\ExportFormat;
use App\Models\Task;
use App\Services\Exports\ExportCells;
use App\Services\Exports\ExportFileWriter;
use App\Support\LocalTime;

/**
 * Writes tasks to a spreadsheet: ids and related ids with readable names, timestamps in the organization timezone
 * (named in their header). Formula safety is that of {@see ExportCells}.
 */
class TaskExportWriter
{
    /** The columns in file order; the four timestamp columns get the timezone appended to their header. */
    public const COLUMNS = [
        'id',
        'title',
        'type',
        'priority',
        'status',
        'due at',
        'completed at',
        'owner',
        'contact id',
        'contact',
        'contact email',
        'deal id',
        'deal',
        'notes',
        'created at',
        'updated at',
    ];

    private const TIMESTAMP_COLUMNS = ['due at', 'completed at', 'created at', 'updated at'];

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
     * The cells of one task, in the order of `headers()`. The task needs its `owner`, `contact` (deleted ones too)
     * and `deal` relations loaded. A task whose deal no longer exists has empty deal cells.
     *
     * @return array<int, string|int|float>
     */
    public function row(Task $task, ExportFormat $format): array
    {
        return ExportCells::row([
            (int) $task->id,
            (string) $task->title,
            (string) $task->type?->getLabel(),
            (string) $task->priority?->getLabel(),
            (string) $task->status?->getLabel(),
            (string) LocalTime::format($task->due_at, self::TIMESTAMP_FORMAT, $this->timezone),
            (string) LocalTime::format($task->completed_at, self::TIMESTAMP_FORMAT, $this->timezone),
            (string) $task->owner?->email,
            $task->contact_id === null ? '' : (int) $task->contact_id,
            (string) $task->contact?->name,
            (string) $task->contact?->email,
            $task->deal === null ? '' : (int) $task->deal_id,
            (string) $task->deal?->title,
            (string) $task->notes,
            (string) LocalTime::format($task->created_at, self::TIMESTAMP_FORMAT, $this->timezone),
            (string) LocalTime::format($task->updated_at, self::TIMESTAMP_FORMAT, $this->timezone),
        ], $format);
    }

    /**
     * Writes the header and the tasks of every chunk to the file; the type comes from its extension.
     *
     * @param  iterable<int, iterable<int, Task>>  $chunks  Tasks with the relations `row()` needs.
     * @return int The number of tasks written.
     */
    public function write(string $path, ExportFormat $format, iterable $chunks): int
    {
        return ExportFileWriter::write(
            $path,
            $this->headers($format),
            $chunks,
            fn (Task $task): array => $this->row($task, $format),
        );
    }
}
