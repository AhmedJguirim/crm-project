<?php

namespace App\Support\Tenancy;

use Filament\Facades\Filament;

/**
 * Which organization the current code works for. The `BelongsToOrganization` scope filters by it, and returns no
 * rows when it is not set.
 *
 * The panel's tenant comes first; otherwise the context set by a job middleware, a route middleware or `run()`.
 * It is registered as a scoped binding, so queue workers start every job with a fresh one.
 */
final class TenantContext
{
    private ?int $organizationId = null;

    public function id(): ?int
    {
        return Filament::getTenant()?->getKey() ?? $this->organizationId;
    }

    /**
     * Runs the callback for the organization and restores the previous context afterwards, even when it throws.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public function run(int $organizationId, callable $callback): mixed
    {
        $previous = $this->organizationId;
        $this->organizationId = $organizationId;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }

    /**
     * Sets the context for the rest of the request. Jobs and commands use `run()`.
     */
    public function set(?int $organizationId): void
    {
        $this->organizationId = $organizationId;
    }
}
