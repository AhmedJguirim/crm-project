<?php

namespace App\Filament\Pages;

use App\Filament\Support\Concerns\HasReportFilters;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

/**
 * The Reports page: a second dashboard with the same period and owner filter bar as the home dashboard, listing the
 * report tables (for now the team activity leaderboard). Every role can open it; the panel home stays the dashboard
 * because the page sorts after it in the navigation.
 */
class Reports extends BaseDashboard
{
    use HasReportFilters;

    protected static string $routePath = 'reports';

    protected static ?string $title = 'Reports';

    protected static ?int $navigationSort = -1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [TeamActivityWidget::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
