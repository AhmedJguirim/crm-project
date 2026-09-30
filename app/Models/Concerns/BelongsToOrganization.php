<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $builder): void {
            $tenantId = Filament::getTenant()?->id;

            if ($tenantId) {
                $builder->where($builder->getModel()->qualifyColumn('organization_id'), $tenantId);
            }
        });

        static::creating(function (Model $model): void {
            $tenantId = Filament::getTenant()?->id;

            if ($tenantId && ! $model->getAttribute('organization_id')) {
                $model->setAttribute('organization_id', $tenantId);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
