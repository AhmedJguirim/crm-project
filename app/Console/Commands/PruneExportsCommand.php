<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PruneExportsCommand extends Command
{
    /** How long a file stays available, as long as the signed download link. */
    private const KEEP_DAYS = 7;

    protected $signature = 'exports:prune';

    protected $description = 'Delete the export files that are more than 7 days old';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $limit = now()->subDays(self::KEEP_DAYS)->getTimestamp();
        $deleted = 0;

        foreach ($disk->files('exports') as $file) {
            if ($disk->lastModified($file) >= $limit) {
                continue;
            }

            $this->info("Deleting {$file}...");
            $disk->delete($file);
            $deleted++;
        }

        $this->comment("Deleted {$deleted} export ".Str::plural('file', $deleted).'.');

        return self::SUCCESS;
    }
}
