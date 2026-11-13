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

    /** Failed-rows files are never pruned here: their retention is a separate decision. */
    private const FAILED_ROWS_PATTERN = '/^failed-[A-Za-z0-9]{40}\.csv\z/';

    protected $signature = 'uploads:prune';

    protected $description = 'Delete the temporary and abandoned import uploads that are more than 24 hours old';

    public function handle(): int
    {
        $limit = now()->subHours(self::KEEP_HOURS)->getTimestamp();
        $deleted = 0;

        if (! FileUploadConfiguration::isUsingS3()) {
            $storage = FileUploadConfiguration::storage();
            $deleted += $this->pruneFiles($storage, $storage->allFiles(FileUploadConfiguration::path()), $limit);
        }

        $local = Storage::disk('local');

        foreach (self::IMPORT_DIRECTORIES as $directory) {
            $deleted += $this->pruneFiles($local, $local->files($directory), $limit, keepFailedRows: true);
        }

        $this->comment("Deleted {$deleted} upload ".Str::plural('file', $deleted).'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $files
     */
    private function pruneFiles(Filesystem $disk, array $files, int $limit, bool $keepFailedRows = false): int
    {
        $deleted = 0;

        foreach ($files as $file) {
            if ($keepFailedRows && preg_match(self::FAILED_ROWS_PATTERN, basename($file)) === 1) {
                continue;
            }

            if (! $this->isOlderThan($disk, $file, $limit)) {
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
