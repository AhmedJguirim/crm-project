<?php

namespace App\Filament\Pages;

use App\Filament\Support\Concerns\HasReportFilters;
use App\Filament\Widgets\Reports\LostReasonsWidget;
use App\Filament\Widgets\Reports\SalesResultsWidget;
use App\Filament\Widgets\Reports\StageConversionWidget;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use App\Filament\Widgets\Reports\TimeInStageWidget;
use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

/**
 * The Reports page: a second dashboard with the same period and owner filter bar as the home dashboard, listing the
 * reports in four tabs, "Sales results" (won and lost deals compared with the previous period, and a chart of the lost
 * reasons), "Conversion" (how far the deals created in the period got through the pipeline), "Velocity" (how long
 * deals stay in each stage) and "Team activity" (the leaderboard). The widgets of a tab load lazily, so a hidden tab
 * runs no query until it is opened. Every role can open it; the panel home stays the dashboard because the page sorts
 * after it in the navigation.
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
        return [SalesResultsWidget::class, LostReasonsWidget::class, StageConversionWidget::class, TimeInStageWidget::class, TeamActivityWidget::class];
    }

    public function getWidgetsContentComponent(): Component
    {
        return Tabs::make('Reports')
            ->contained(false)
            ->persistTabInQueryString()
            ->tabs([
                Tab::make('Sales results')
                    ->key('sales-results')
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([SalesResultsWidget::class, LostReasonsWidget::class])),
                Tab::make('Conversion')
                    ->key('conversion')
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([StageConversionWidget::class])),
                Tab::make('Velocity')
                    ->key('velocity')
                    ->schema(fn (): array => $this->getWidgetsSchemaComponents([TimeInStageWidget::class])),
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
