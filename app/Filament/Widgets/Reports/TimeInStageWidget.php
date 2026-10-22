<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Services\Reports\TimeInStage;
use App\Support\Reports\DashboardFilters;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Reports page table of how long deals stay in each open stage (see TimeInStage). It follows the period and owner
 * filters and is not discovered onto the dashboard: only the Reports page lists it.
 */
class TimeInStageWidget extends TableWidget
{
    use InteractsWithPageFilters;
    use SanitizesTableSearch;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $filters = DashboardFilters::fromPageFilters($this->pageFilters);
        $rows = TimeInStage::forPeriod($filters->period, $filters->ownerId);

        return $table
            ->heading('Time in stage')
            ->description('Average days deals stayed in each stage, from the stays that ended '.$filters->period->phrase().', next to the open deals in each stage now. An open deal is over the average when it has been in its stage longer than that average; a deal without stage history counts in its stage but is never over it.')
            ->records(fn (): array => $rows)
            ->paginated(false)
            ->emptyStateHeading('No open stages')
            ->emptyStateDescription('The pipeline has no open stage.')
            ->columns([
                TextColumn::make('stage')
                    ->label('Stage'),
                TextColumn::make('average_days')
                    ->label('Average days')
                    ->alignEnd(),
                TextColumn::make('stays')
                    ->label('Stays')
                    ->alignEnd(),
                TextColumn::make('in_stage_now')
                    ->label('In stage now')
                    ->alignEnd(),
                TextColumn::make('over_average')
                    ->label('Over average')
                    ->alignEnd(),
            ]);
    }
}
