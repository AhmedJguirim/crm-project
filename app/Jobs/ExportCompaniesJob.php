<?php

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Jobs\Concerns\DeliversExport;
use App\Models\Company;
use App\Models\CompanyCustomField;
use App\Services\CompanyExportWriter;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Writes the given companies to a spreadsheet and tells the user when it is ready to download.
 *
 * It runs on the `imports` queue of the long-running connection (a Horizon supervisor listens there, with a timeout
 * above the one of this job) and reads the companies 500 at a time, in the order of the given ids.
 */
class ExportCompaniesJob implements ShouldQueue
{
    use DeliversExport;
    use Queueable;

    private const CHUNK_SIZE = 500;

    /**
     * @param  array<int, int>  $companyIds  In the order of the file.
     * @param  string  $format  A value of {@see ExportFormat}.
     * @param  string  $downloadName  The file name the user gets, with its extension.
     */
    public function __construct(
        private readonly array $companyIds,
        private readonly int $organizationId,
        private readonly int $userId,
        private readonly string $format,
        private readonly string $downloadName,
    ) {
        $this->prepareExportQueue();
    }

    protected function exportFilePrefix(): string
    {
        return 'companies';
    }

    protected function exportNoun(): string
    {
        return 'company';
    }

    protected function writeExport(string $temporaryPath, ExportFormat $format): int
    {
        $customFields = CompanyCustomField::forOrganization($this->organizationId)->orderBy('order')->get();

        return (new CompanyExportWriter($customFields))->write($temporaryPath, $format, $this->chunks());
    }

    /**
     * The companies of the export, one chunk at a time, in the order of the ids. A company that no longer exists is
     * skipped; trashed ones are exported.
     *
     * @return Generator<int, Collection<int, Company>>
     */
    private function chunks(): Generator
    {
        $positions = array_flip($this->companyIds);

        foreach (array_chunk($this->companyIds, self::CHUNK_SIZE) as $ids) {
            yield Company::query()
                ->forOrganization($this->organizationId)
                ->withTrashed()
                ->with(['address', 'companyTypeWithTrashed'])
                ->withCount('contacts')
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn (Company $company): int => $positions[$company->getKey()])
                ->values();
        }
    }
}
