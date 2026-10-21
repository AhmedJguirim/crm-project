<?php

namespace App\Filament\Pages;

use App\Filament\Support\Concerns\HasReportFilters;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The panel dashboard with a filter bar (period and owner) that the report widgets read through
 * `App\Support\Reports\DashboardFilters`, which validates the values.
 */
class Dashboard extends BaseDashboard
{
    use HasReportFilters;
}
