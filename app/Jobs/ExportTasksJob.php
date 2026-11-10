<?php

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Jobs\Concerns\DeliversExport;
use App\Models\Organization;
use App\Models\Task;
use App\Services\TaskExportWriter;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Writes the given tasks to a spreadsheet and tells the user when it is ready to download.
 *
 * It runs on the `imports` queue of the long-running connection (a Horizon supervisor listens there, with a timeout
 * above the one of this job) and reads the tasks 500 at a time, in the order of the given ids.
 */
class ExportTasksJob implements ShouldQueue
{
    use DeliversExport;
    use Queueable;

    private const CHUNK_SIZE = 500;

    /**
     * @param  array<int, int>  $taskIds  In the order of the file.
     * @param  string  $format  A value of {@see ExportFormat}.
     * @param  string  $downloadName  The file name the user gets, with its extension.
     */
    public function __construct(
        private readonly array $taskIds,
        private readonly int $organizationId,
        private readonly int $userId,
        private readonly string $format,
        private readonly string $downloadName,
    ) {
        $this->prepareExportQueue();
    }

    protected function exportFilePrefix(): string
    {
        return 'tasks';
    }

    protected function exportNoun(): string
    {
        return 'task';
    }

    protected function writeExport(string $temporaryPath, ExportFormat $format): int
    {
        $organization = Organization::find($this->organizationId);

        $writer = new TaskExportWriter(Organization::validTimezoneOrUtc($organization?->timezone));

        return $writer->write($temporaryPath, $format, $this->chunks());
    }

    /**
     * The tasks of the export, one chunk at a time, in the order of the ids. A task that was deleted, or that
     * belongs to another organization, is skipped.
     *
     * @return Generator<int, Collection<int, Task>>
     */
    private function chunks(): Generator
    {
        $positions = array_flip($this->taskIds);

        foreach (array_chunk($this->taskIds, self::CHUNK_SIZE) as $ids) {
            yield Task::query()
                ->forOrganization($this->organizationId)
                ->with([
                    'owner',
                    'contact' => fn (BelongsTo $query) => $query->withTrashed(),
                    'deal',
                ])
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn (Task $task): int => $positions[$task->getKey()])
                ->values();
        }
    }
}
