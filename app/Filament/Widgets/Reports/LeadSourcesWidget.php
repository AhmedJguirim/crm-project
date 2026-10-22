<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Services\Reports\LeadSources;
use App\Support\Reports\DashboardFilters;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/**
 * Reports page table of the contacts created and the deals won per lead source in the page's period, with the share of
 * the won value of each source (see LeadSources). It follows the period and owner filters and is not discovered onto
 * the dashboard: only the Reports page lists it.
 */
class LeadSourcesWidget extends TableWidget
{
    use InteractsWithPageFilters;
    use SanitizesTableSearch;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';
        $filters = DashboardFilters::fromPageFilters($this->pageFilters);
        $phrase = $filters->period->phrase();
        $rows = LeadSources::forPeriod($filters->period, $filters->ownerId);

        $top = collect($rows)->first(fn (array $row, string $key): bool => ! in_array($key, [LeadSources::UNKNOWN, 'total'], true) && $row['won_value'] > 0);

        $sentence = $top !== null
            ? 'Top source: '.$top['source'].', '.$top['share'].' of the value won '.$phrase.'. '
            : '';

        return $table
            ->heading('Leads and deals by source')
            ->description($sentence.'Contacts created '.$phrase.' and deals won '.$phrase.', by lead source. A won deal counts for the current lead source of its contact; a deal without a contact, or a contact without a source, counts as "Unknown source".')
            ->records(fn (): array => $rows)
            ->paginated(false)
            ->emptyStateHeading('No new contacts or won deals')
            ->emptyStateDescription('No contact was created and no deal was won '.$phrase.'.')
            ->columns([
                TextColumn::make('source')
                    ->label('Source')
                    ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null),
                TextColumn::make('new_contacts')
                    ->label('New contacts')
                    ->alignEnd(),
                TextColumn::make('won_count')
                    ->label('Deals won')
                    ->alignEnd(),
                TextColumn::make('won_value')
                    ->label('Won value')
                    ->alignEnd()
                    ->formatStateUsing(fn (float $state): string => Number::currency($state, $currency)),
                TextColumn::make('share')
                    ->label('Share')
                    ->alignEnd(),
            ]);
    }
}
