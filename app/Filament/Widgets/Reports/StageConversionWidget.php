<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Services\Reports\StageConversion;
use App\Support\Reports\DashboardFilters;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/**
 * Reports page table of how far the deals created in the page's period got through the pipeline (see
 * StageConversion). It follows the period and owner filters and is not discovered onto the dashboard: only the
 * Reports page lists it.
 */
class StageConversionWidget extends TableWidget
{
    use InteractsWithPageFilters;
    use SanitizesTableSearch;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $filters = DashboardFilters::fromPageFilters($this->pageFilters);
        $conversion = StageConversion::forPeriod($filters->period, $filters->ownerId);

        return $table
            ->heading('Stage conversion')
            ->description('Deals created '.$filters->period->phrase().' ('.$conversion['deals_created'].') and the furthest stage each one reached. A skipped stage counts as reached, lost deals count up to the stage they were lost from, and the last row counts the deals won now.')
            ->records(fn (): array => $conversion['rows'])
            ->paginated(false)
            ->emptyStateHeading('No new deals')
            ->emptyStateDescription('No deals were created '.$filters->period->phrase().'.')
            ->columns([
                TextColumn::make('stage')
                    ->label('Stage')
                    ->weight(fn (array $record): ?FontWeight => $record['is_won'] ? FontWeight::Bold : null),
                TextColumn::make('deals')
                    ->label('Deals')
                    ->alignEnd(),
                TextColumn::make('of_created')
                    ->label('Of new deals')
                    ->alignEnd(),
                TextColumn::make('from_previous')
                    ->label('From previous stage')
                    ->alignEnd(),
            ]);
    }
}
