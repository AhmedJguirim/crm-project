<?php

namespace App\Filament\Widgets\Reports;

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
}
