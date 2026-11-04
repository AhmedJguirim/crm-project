<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard line chart of the deals created over time, of the page's period (owner filter on the deal owner).
 */
class DealsOverTimeWidget extends CreatedOverTimeChartWidget
{
    protected ?string $heading = 'Deals created over time';

    protected int|string|array $columnSpan = 'full';

    protected function recordsFor(?int $ownerId): Builder
    {
        return Deal::query()->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId));
    }

    protected function seriesLabel(): string
    {
        return 'Deals created';
    }

    protected function color(): string
    {
        return '#ea580c';
    }
}
