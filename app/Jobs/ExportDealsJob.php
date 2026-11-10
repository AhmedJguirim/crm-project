<?php

namespace App\Jobs;

use App\Enums\Currency;
use App\Enums\ExportFormat;
use App\Jobs\Concerns\DeliversExport;
use App\Models\Deal;
use App\Models\Organization;
use App\Services\DealExportWriter;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Writes the given deals to a spreadsheet and tells the user when it is ready to download.
 *
 * It runs on the `imports` queue of the long-running connection (a Horizon supervisor listens there, with a timeout
 * above the one of this job) and reads the deals 500 at a time, in the order of the given ids.
 */
class ExportDealsJob implements ShouldQueue
{
    use DeliversExport;
    use Queueable;

    private const CHUNK_SIZE = 500;

    /**
     * @param  array<int, int>  $dealIds  In the order of the file.
     * @param  string  $format  A value of {@see ExportFormat}.
     * @param  string  $downloadName  The file name the user gets, with its extension.
     */
    public function __construct(
        private readonly array $dealIds,
        private readonly int $organizationId,
        private readonly int $userId,
        private readonly string $format,
        private readonly string $downloadName,
    ) {
        $this->prepareExportQueue();
    }

    protected function exportFilePrefix(): string
    {
        return 'deals';
    }

    protected function exportNoun(): string
    {
        return 'deal';
    }

    protected function writeExport(string $temporaryPath, ExportFormat $format): int
    {
        $organization = Organization::find($this->organizationId);

        $writer = new DealExportWriter(
            Organization::validTimezoneOrUtc($organization?->timezone),
            $organization?->currencyCode() ?? Currency::Usd->value,
        );

        return $writer->write($temporaryPath, $format, $this->chunks());
    }

    /**
     * The deals of the export, one chunk at a time, in the order of the ids. A deal that no longer exists, or that
     * belongs to another organization, is skipped.
     *
     * @return Generator<int, Collection<int, Deal>>
     */
    private function chunks(): Generator
    {
        $positions = array_flip($this->dealIds);

        foreach (array_chunk($this->dealIds, self::CHUNK_SIZE) as $ids) {
            yield Deal::query()
                ->forOrganization($this->organizationId)
                ->with([
                    'pipelineStage',
                    'lostReason',
                    'owner',
                    'contact' => fn (BelongsTo $query) => $query->withTrashed(),
                    'companyWithTrashed',
                ])
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn (Deal $deal): int => $positions[$deal->getKey()])
                ->values();
        }
    }
}
