<?php

namespace App\Filament\Pages;

use App\Filament\Support\Concerns\HasReportFilters;
use App\Filament\Widgets\DashboardV2\ActivityTypesWidget;
use App\Filament\Widgets\DashboardV2\AverageTimeToCloseWidget;
use App\Filament\Widgets\DashboardV2\ContactSourcesWidget;
use App\Filament\Widgets\DashboardV2\ContactsOverTimeWidget;
use App\Filament\Widgets\DashboardV2\DealRevenueBySourceWidget;
use App\Filament\Widgets\DashboardV2\DealsByStageWidget;
use App\Filament\Widgets\DashboardV2\DealsByStatusWidget;
use App\Filament\Widgets\DashboardV2\DealsClosedWidget;
use App\Filament\Widgets\DashboardV2\DealsCreatedWidget;
use App\Filament\Widgets\DashboardV2\DealsOverTimeWidget;
use App\Filament\Widgets\DashboardV2\LeadsBySourceWidget;
use App\Filament\Widgets\DashboardV2\LeadsFunnelWidget;
use App\Filament\Widgets\DashboardV2\LeadsSummaryWidget;
use App\Filament\Widgets\DashboardV2\LeadsWorkedWidget;
use App\Filament\Widgets\DashboardV2\NewContactsWidget;
use App\Filament\Widgets\DashboardV2\OpenTasksWidget;
use App\Filament\Widgets\DashboardV2\OwnerPerformanceWidget;
use App\Filament\Widgets\DashboardV2\TaskStatusWidget;
use App\Filament\Widgets\DealForecastWidget;
use App\Filament\Widgets\DealsNeedingAttentionWidget;
use App\Filament\Widgets\GettingStartedWidget;
use App\Filament\Widgets\KpiOverviewWidget;
use App\Filament\Widgets\MoneyOwedWidget;
use App\Filament\Widgets\MyDealsClosingWidget;
use App\Filament\Widgets\MyTasksWidget;
use App\Filament\Widgets\OverdueInvoicesWidget;
use App\Filament\Widgets\Reports\LostReasonsWidget;
use App\Filament\Widgets\Reports\StageConversionWidget;
use App\Filament\Widgets\Reports\TeamActivityWidget;
use App\Filament\Widgets\RevenueByMonthWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;

/**
 * The panel dashboard: four dashboards to switch between with tabs, "Data overview" (contacts, deals, activities, team
 * and open tasks), "Leads" (the contacts with the status Lead, how they are worked and where they come from),
 * "Pipeline" (the deals created and closed, their progression, the time to close and the deals needing attention) and
 * "Finance" (the key figures with the cash collected, the money owed, the overdue invoices, the revenue by month, the
 * forecast and the user's own tasks and deals closing). They share the period and owner filter bar (the report widgets
 * read it through `App\Support\Reports\DashboardFilters`, which validates the values) with the Reports page; the
 * widgets of a tab load lazily, so a hidden tab runs no query until it is opened. Every role can open it. Above the
 * filter bar and the tabs, owners, admins and members see the "Get started" card until the first contact, deal, task and
 * invoice exist or they dismiss it.
 *
 * The CRM has no ticket system and no workflows: the open tasks stand for the tickets, the lost deal reasons for the
 * disqualified reasons of the leads, and nothing stands for the leads created by workflows.
 */
class Dashboard extends BaseDashboard
{
    use HasReportFilters;

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [GettingStartedWidget::class];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [...$this->dataOverviewWidgets(), ...$this->leadsWidgets(), ...$this->pipelineWidgets(), ...$this->financeWidgets()];
    }

    public function getWidgetsContentComponent(): Component
    {
        return Tabs::make('Dashboards')
            ->contained(false)
            ->persistTabInQueryString()
            ->tabs([
                Tab::make('Data overview')
                    ->key('data-overview')
                    ->icon(Heroicon::OutlinedChartPie)
                    ->schema(fn (): array => [$this->dataOverviewLayout()]),
                Tab::make('Leads')
                    ->key('leads')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->schema(fn (): array => [$this->tabGrid($this->leadsWidgets())]),
                Tab::make('Pipeline')
                    ->key('pipeline')
                    ->icon(Heroicon::OutlinedFunnel)
                    ->schema(fn (): array => [$this->tabGrid($this->pipelineWidgets())]),
                Tab::make('Finance')
                    ->key('finance')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->schema(fn (): array => [$this->tabGrid($this->financeWidgets())]),
            ]);
    }

    /**
     * @return array<class-string>
     */
    private function dataOverviewWidgets(): array
    {
        return [
            NewContactsWidget::class,
            ContactsOverTimeWidget::class,
            ContactSourcesWidget::class,
            OpenTasksWidget::class,
            DealsByStageWidget::class,
            DealsOverTimeWidget::class,
            TaskStatusWidget::class,
            ActivityTypesWidget::class,
            TeamActivityWidget::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    private function leadsWidgets(): array
    {
        return [
            LeadsSummaryWidget::class,
            LeadsBySourceWidget::class,
            LeadsFunnelWidget::class,
            LostReasonsWidget::class,
            LeadsWorkedWidget::class,
            DealRevenueBySourceWidget::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    private function pipelineWidgets(): array
    {
        return [
            DealsCreatedWidget::class,
            DealsClosedWidget::class,
            DealsByStatusWidget::class,
            OwnerPerformanceWidget::class,
            StageConversionWidget::class,
            AverageTimeToCloseWidget::class,
            DealsNeedingAttentionWidget::class,
        ];
    }

    /**
     * @return array<class-string>
     */
    private function financeWidgets(): array
    {
        return [
            KpiOverviewWidget::class,
            MoneyOwedWidget::class,
            RevenueByMonthWidget::class,
            OverdueInvoicesWidget::class,
            DealForecastWidget::class,
            MyTasksWidget::class,
            MyDealsClosingWidget::class,
        ];
    }

    /**
     * The data overview in two columns of rows that do not all have the same height: new contacts over the (squeezed)
     * contacts chart beside the contact sources, the open tasks beside the deals by stage, the deals over time across
     * the width, the task statuses beside the activity types, and the team activity across the width.
     */
    private function dataOverviewLayout(): Component
    {
        return Grid::make($this->getColumns())->schema([
            $this->stack([NewContactsWidget::class, ContactsOverTimeWidget::class]),
            ...$this->getWidgetsSchemaComponents([ContactSourcesWidget::class]),
            ...$this->getWidgetsSchemaComponents([OpenTasksWidget::class, DealsByStageWidget::class, DealsOverTimeWidget::class, TaskStatusWidget::class, ActivityTypesWidget::class, TeamActivityWidget::class]),
        ]);
    }

    /**
     * Widgets one under the other in a single grid cell.
     *
     * @param  array<class-string>  $widgets
     */
    private function stack(array $widgets): Component
    {
        return Grid::make(1)->schema($this->getWidgetsSchemaComponents($widgets));
    }

    /**
     * @param  array<class-string>  $widgets
     */
    private function tabGrid(array $widgets): Component
    {
        return Grid::make($this->getColumns())->schema($this->getWidgetsSchemaComponents($widgets));
    }
}
