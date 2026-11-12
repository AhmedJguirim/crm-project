<?php

namespace App\Jobs\Middleware;

use App\Jobs\Concerns\HoldsImportExportSlot;
use Closure;

/**
 * Gives the job's {@see HoldsImportExportSlot} slot back when the run ends, whether it returns or throws. A job that is
 * released back to the queue by a middleware placed before this one never reaches it, so it keeps its slot.
 */
final class ReleasesImportExportSlot
{
    /**
     * @param  object&HoldsImportExportSlot  $job
     */
    public function handle(object $job, Closure $next): mixed
    {
        try {
            return $next($job);
        } finally {
            $job->releaseImportExportSlot();
        }
    }
}
