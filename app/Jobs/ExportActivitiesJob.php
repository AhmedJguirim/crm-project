<?php

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Jobs\Concerns\DeliversExport;
use App\Models\Activity;
use App\Models\Organization;
use App\Services\ActivityExportWriter;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Writes the given activities to a spreadsheet and tells the user when it is ready to download.
 *
 * It runs on the `imports` queue of the long-running connection (a Horizon supervisor listens there, with a timeout
 * above the one of this job) and reads the activities 500 at a time, in the order of the given ids.
 */
class ExportActivitiesJob implements ShouldQueue
{
    use DeliversExport;
    use Queueable;

    private const CHUNK_SIZE = 500;

    /**
     * @param  array<int, int>  $activityIds  In the order of the file.
     * @param  string  $format  A value of {@see ExportFormat}.
     * @param  string  $downloadName  The file name the user gets, with its extension.
     */
    public function __construct(
        private readonly array $activityIds,
        private readonly int $organizationId,
        private readonly int $userId,
        private readonly string $format,
        private readonly string $downloadName,
    ) {
        $this->prepareExportQueue();
    }

    protected function exportFilePrefix(): string
    {
        return 'activities';
    }

    protected function exportNoun(): string
    {
        return 'activity';
    }

    protected function writeExport(string $temporaryPath, ExportFormat $format): int
    {
        $organization = Organization::find($this->organizationId);

        $writer = new ActivityExportWriter(Organization::validTimezoneOrUtc($organization?->timezone));

        return $writer->write($temporaryPath, $format, $this->chunks());
    }

    /**
     * The activities of the export, one chunk at a time, in the order of the ids. An activity that was deleted, or
     * that belongs to another organization, is skipped.
     *
     * @return Generator<int, Collection<int, Activity>>
     */
    private function chunks(): Generator
    {
        $positions = array_flip($this->activityIds);

        foreach (array_chunk($this->activityIds, self::CHUNK_SIZE) as $ids) {
            yield Activity::query()
                ->forOrganization($this->organizationId)
                ->with([
                    'user',
                    'contact' => fn (BelongsTo $query) => $query->withTrashed(),
                    'deal',
                    'company' => fn (BelongsTo $query) => $query->withTrashed(),
                ])
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn (Activity $activity): int => $positions[$activity->getKey()])
                ->values();
        }
    }
}
