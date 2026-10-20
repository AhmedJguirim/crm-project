<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Services\Deals\DealForecast;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Number;

/**
 * Dashboard table of the open deals' total and weighted value per expected close month (see DealForecast).
 */
class DealForecastWidget extends TableWidget
{
    use SanitizesTableSearch;

    protected static ?int $sort = -18;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';

        return $table
            ->heading('Forecast by close month')
            ->description('Open deals by expected close month. Weighted = value × the stage\'s win probability.')
            ->records(fn (): array => DealForecast::byCloseMonth(LocalTime::todayDate()))
            ->paginated(false)
            ->columns([
                TextColumn::make('label')
                    ->label('Close month'),
                TextColumn::make('deals_count')
                    ->label('Deals')
                    ->alignEnd(),
                TextColumn::make('total')
                    ->label('Total')
                    ->alignEnd()
                    ->formatStateUsing(fn (float $state): string => Number::currency($state, $currency)),
                TextColumn::make('weighted')
                    ->label('Weighted')
                    ->alignEnd()
                    ->formatStateUsing(fn (float $state): string => Number::currency($state, $currency)),
            ]);
    }
}
