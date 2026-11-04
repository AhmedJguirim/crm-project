<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Support\Reports\DashboardFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;

/**
 * The base of the Dashboard stat rows: they follow the page's period and owner filters (validated through
 * `DashboardFilters`), span the full row, never poll, and are not discovered by the panel (the Dashboard page lists them).
 */
abstract class DashboardStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function filters(): DashboardFilters
    {
        return DashboardFilters::fromPageFilters($this->pageFilters);
    }
}
