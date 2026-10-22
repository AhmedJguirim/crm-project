<?php

namespace App\Filament\Widgets\Reports;

use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Services\Reports\RevenueByClient;
use App\Support\Reports\DashboardFilters;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/**
 * Reports page table of the money collected per client in the page's period, each client's share, and what it still
 * owes today (see RevenueByClient). It follows the period and owner filters and is not discovered onto the dashboard:
 * only the Reports page lists it.
 */
class RevenueByClientWidget extends TableWidget
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
        $rows = RevenueByClient::forPeriod($filters->period, $filters->ownerId);
        $top = array_values($rows)[0] ?? null;
        $total = $rows['total'] ?? null;

        $sentence = $top !== null && ! $top['is_total'] && $top['collected'] > 0 && $total !== null && $total['collected'] > 0
            ? 'Top client: '.$top['client'].', '.$top['share'].' of the money collected '.$phrase.'. '
            : '';

        return $table
            ->heading('Revenue by client')
            ->description($sentence.'Money collected '.$phrase.' (payments minus refunds) and the balance still owed today, by client. An invoice counts for the company of its deal, else for its contact\'s company when the contact has exactly one, else for the contact.')
            ->records(fn (): array => $rows)
            ->paginated(false)
            ->emptyStateHeading('No revenue')
            ->emptyStateDescription('No money was collected '.$phrase.' and no invoice is unpaid.')
            ->columns([
                TextColumn::make('client')
                    ->label('Client')
                    ->description(fn (array $record): ?string => $record['kind'])
                    ->weight(fn (array $record): ?FontWeight => $record['is_total'] ? FontWeight::Bold : null),
                TextColumn::make('collected')
                    ->label('Collected')
                    ->alignEnd()
                    ->formatStateUsing(fn (float $state): string => Number::currency($state, $currency)),
                TextColumn::make('share')
                    ->label('Share')
                    ->alignEnd(),
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->alignEnd()
                    ->formatStateUsing(fn (float $state): string => Number::currency($state, $currency)),
            ]);
    }
}
