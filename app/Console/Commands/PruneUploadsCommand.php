<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToRetrieveMetadata;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

class PruneUploadsCommand extends Command
{
    /**
     * Longer than the longest an import can wait and run (`retryUntil()` 3 hours + `$timeout` 1740 s),
     * and than Livewire's own one-day cleanup.
     */
    private const KEEP_HOURS = 24;

    /** Import upload folders on the local disk (top level only). */
    private const IMPORT_DIRECTORIES = ['contact-imports', 'company-imports'];

    /**
     * As long as the signed download link (`ReportsImportResults::failedRowsAction()`), so the file always outlives its link.
     */
    private const FAILED_ROWS_KEEP_DAYS = 7;

    /** Failed-rows files keep the 7-day rule, not the 24-hour one. */
    private const FAILED_ROWS_PATTERN = '/^failed-[A-Za-z0-9]{40}\.csv\z/';

    protected $signature = 'uploads:prune';

    protected $description = 'Delete the temporary and abandoned import uploads older than 24 hours and the failed-rows files older than 7 days';

    public function handle(): int
    {
        $limit = now()->subHours(self::KEEP_HOURS)->getTimestamp();
        $failedRowsLimit = now()->subDays(self::FAILED_ROWS_KEEP_DAYS)->getTimestamp();
        $deleted = 0;

        if (! FileUploadConfiguration::isUsingS3()) {
            $storage = FileUploadConfiguration::storage();
            $deleted += $this->pruneFiles($storage, $storage->allFiles(FileUploadConfiguration::path()), $limit);
        }

        $local = Storage::disk('local');

        foreach (self::IMPORT_DIRECTORIES as $directory) {
            $deleted += $this->pruneFiles($local, $local->files($directory), $limit, failedRowsLimit: $failedRowsLimit);
        }

        $this->comment("Deleted {$deleted} upload ".Str::plural('file', $deleted).'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $files
     * @param  int|null  $failedRowsLimit  Timestamp before which a failed-rows file is old; null = no special rule.
     */
    private function pruneFiles(Filesystem $disk, array $files, int $limit, ?int $failedRowsLimit = null): int
    {
        $deleted = 0;

        foreach ($files as $file) {
            $isFailedRows = $failedRowsLimit !== null && preg_match(self::FAILED_ROWS_PATTERN, basename($file)) === 1;

            if (! $this->isOlderThan($disk, $file, $isFailedRows ? $failedRowsLimit : $limit)) {
                continue;
            }

            $this->info("Deleting {$file}...");
            $disk->delete($file);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * A file that vanished since the listing (Livewire's cleanup, a finishing import) is not old: skip it.
     */
    private function isOlderThan(Filesystem $disk, string $file, int $limit): bool
    {
        try {
            return $disk->lastModified($file) < $limit;
        } catch (UnableToRetrieveMetadata) {
            return false;
        }
    }
}
