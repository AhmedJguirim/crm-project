<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Actions\ExportReportTableAction;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Services\Reports\TeamActivity;
use App\Support\Reports\DashboardFilters;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/**
 * Reports page table of what each member logged and closed in the page's period (see TeamActivity). It follows the
 * period and owner filters and is not discovered onto the dashboard: only the Reports page lists it.
 */
class TeamActivityWidget extends TableWidget
{
    use InteractsWithPageFilters;
    use SanitizesTableSearch;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';
        $filters = DashboardFilters::fromPageFilters($this->pageFilters);

        return $table
            ->heading('Team activity')
            ->description('Per member, '.$filters->period->phrase().'. Overdue tasks are counted as of now; unassigned records are not counted.')
            ->records(fn (): array => TeamActivity::forPeriod($filters->period, $filters->ownerId))
            ->paginated(false)
            ->headerActions([
                ExportReportTableAction::make(
                    'team-activity',
                    'team activity',
                    $filters,
                    fn (): array => $this->exportSheet(TeamActivity::forPeriod($filters->period, $filters->ownerId), $currency),
                ),
            ])
            ->columns([
                TextColumn::make('member')
                    ->label('Member')
                    ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null),
                TextColumn::make('activities')
                    ->label('Activities')
                    ->alignEnd()
                    ->description(fn (array $record): ?string => $record['activities_breakdown'] !== '' ? $record['activities_breakdown'] : null),
                TextColumn::make('tasks_completed')
                    ->label('Tasks done')
                    ->alignEnd(),
                TextColumn::make('tasks_overdue')
                    ->label('Overdue tasks')
                    ->alignEnd(),
                TextColumn::make('deals_created')
                    ->label('New deals')
                    ->alignEnd(),
                TextColumn::make('deals_won')
                    ->label('Deals won')
                    ->alignEnd(),
                TextColumn::make('won_value')
                    ->label('Won value')
                    ->alignEnd()
                    ->formatStateUsing(fn (float $state): string => Number::currency($state, $currency)),
                TextColumn::make('win_rate')
                    ->label('Win rate')
                    ->alignEnd(),
            ]);
    }

    /**
     * The export file of the table: the rows as the table shows them, money as plain numbers with the currency in the header.
     *
     * @param  array<string, array{member: string, is_total: bool, activities: int, activities_breakdown: string, tasks_completed: int, tasks_overdue: int, deals_created: int, deals_won: int, won_value: float, deals_lost: int, win_rate: string}>  $rows
     * @return array{headers: list<string>, rows: list<list<string|int|float>>}
     */
    private function exportSheet(array $rows, string $currency): array
    {
        return [
            'headers' => ['Member', 'Activities', 'Activities by type', 'Tasks done', 'Overdue tasks', 'New deals', 'Deals won', "Won value ({$currency})", 'Win rate'],
            'rows' => array_values(array_map(fn (array $row): array => [
                $row['member'],
                $row['activities'],
                $row['activities_breakdown'],
                $row['tasks_completed'],
                $row['tasks_overdue'],
                $row['deals_created'],
                $row['deals_won'],
                round($row['won_value'], 2),
                $row['win_rate'],
            ], $rows)),
        ];
    }
}
