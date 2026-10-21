<?php

namespace App\Filament\Pages;

use App\Filament\Support\Concerns\HasReportFilters;
use App\Filament\Widgets\Reports\SalesResultsWidget;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

/**
 * The Reports page: a second dashboard with the same period and owner filter bar as the home dashboard, listing the
 * reports in two tabs, "Sales results" (won and lost deals compared with the previous period) and "Team activity" (the
 * leaderboard). The widgets of a tab load lazily, so a hidden tab runs no query until it is opened. Every role can open
 * it; the panel home stays the dashboard because the page sorts after it in the navigation.
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
        return [SalesResultsWidget::class, TeamActivityWidget::class];
    }

    public function getWidgetsContentComponent(): Component
    {
        return Tabs::make('Reports')
            ->contained(false)
            ->persistTabInQueryString()
            ->tabs([
                Tab::make('Sales results')
                    ->key('sales-results')
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([SalesResultsWidget::class])),
                Tab::make('Team activity')
                    ->key('team-activity')
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([TeamActivityWidget::class])),
            ]);
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
