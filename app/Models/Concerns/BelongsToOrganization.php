<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the organization the code works for (see `TenantContext`): the panel's tenant, a job or route
 * middleware, or `TenantContext::run()`.
 *
 * The scope fails closed: with no organization set, queries return no rows. Code that works across organizations must
 * opt out explicitly, with `withoutGlobalScope('organization')` and its own `organization_id` filter, or with
 * `forOrganization()`, which does both. In tinker or a script, set a context first:
 * `app(TenantContext::class)->run($organizationId, fn () => …)`.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $organizationId = app(TenantContext::class)->id();

            if ($organizationId === null) {
                $builder->whereRaw('false');

                return;
            }

            $builder->where($builder->getModel()->qualifyColumn('organization_id'), $organizationId);
        });

        static::creating(function (Model $model): void {
            $organizationId = app(TenantContext::class)->id();

            if ($organizationId && ! $model->getAttribute('organization_id')) {
                $model->setAttribute('organization_id', $organizationId);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Explicitly queries one organization's rows, whatever the current tenant context is.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForOrganization(Builder $query, int $organizationId): void
    {
        $query
            ->withoutGlobalScope('organization')
            ->where($this->qualifyColumn('organization_id'), $organizationId);
    }
}
