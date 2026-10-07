<?php

namespace App\Jobs;

use App\Enums\ExportFormat;
use App\Jobs\Concerns\DeliversExport;
use App\Models\Contact;
use App\Models\CustomField;
use App\Services\ContactExportWriter;
use Generator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

/**
 * Writes the given contacts to a spreadsheet and tells the user when it is ready to download.
 *
 * It runs on the `imports` queue of the long-running connection (a Horizon supervisor listens there, with a timeout
 * above the one of this job) and reads the contacts 500 at a time, in the order of the given ids.
 */
class ExportContactsJob implements ShouldQueue
{
    use DeliversExport;
    use Queueable;

    private const CHUNK_SIZE = 500;

    /**
     * @param  array<int, int>  $contactIds  In the order of the file.
     * @param  string  $format  A value of {@see ExportFormat}.
     * @param  string  $downloadName  The file name the user gets, with its extension.
     */
    public function __construct(
        private readonly array $contactIds,
        private readonly int $organizationId,
        private readonly int $userId,
        private readonly string $format,
        private readonly string $downloadName,
    ) {
        $this->prepareExportQueue();
    }

    protected function exportFilePrefix(): string
    {
        return 'contacts';
    }

    protected function exportNoun(): string
    {
        return 'contact';
    }

    protected function writeExport(string $temporaryPath, ExportFormat $format): int
    {
        $customFields = CustomField::forOrganization($this->organizationId)->orderBy('order')->get();

        return (new ContactExportWriter($customFields))->write($temporaryPath, $format, $this->chunks());
    }

    /**
     * The contacts of the export, one chunk at a time, in the order of the ids. A contact that no longer exists is
     * skipped; trashed ones are exported.
     *
     * @return Generator<int, Collection<int, Contact>>
     */
    private function chunks(): Generator
    {
        $positions = array_flip($this->contactIds);

        foreach (array_chunk($this->contactIds, self::CHUNK_SIZE) as $ids) {
            yield Contact::query()
                ->forOrganization($this->organizationId)
                ->withTrashed()
                ->with(['tags', 'companies'])
                ->whereIn('id', $ids)
                ->get()
                ->sortBy(fn (Contact $contact): int => $positions[$contact->getKey()])
                ->values();
        }
    }
}
