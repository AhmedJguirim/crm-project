<?php

namespace App\Jobs\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Runs a job, or a queued notification, inside its organization's tenant context.
 *
 * A closure is resolved when the job runs. When it returns null (the record is gone), the job runs with no context, so
 * tenant queries return nothing and the job's own "not found" path applies.
 */
final class WithTenantContext
{
    /**
     * @param  int|Closure(): ?int  $organization
     */
    public function __construct(private readonly int|Closure $organization) {}

    public function handle(object $job, Closure $next): mixed
    {
        $organizationId = $this->organization instanceof Closure ? ($this->organization)() : $this->organization;

        if ($organizationId === null) {
            return $next($job);
        }

        return app(TenantContext::class)->run($organizationId, fn (): mixed => $next($job));
    }
}
