<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class RevenueOverviewWidget extends BaseWidget
{
    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = -19;

    protected int|string|array $columnSpan = 1;

    public function getSectionContentComponent(): Component
    {
        return Section::make()
            ->schema([
                ...$this->getCachedStats(),

                View::make('filament.widgets.revenue-overview-footer')
                    ->viewData([
                        'invoicesUrl' => InvoiceResource::getUrl('index'),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns($this->getColumns())
            ->contained(false)
            ->gridContainer();
    }

    protected function getStats(): array
    {
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';

        $paidThisMonth = (float) Invoice::query()
            ->paid()
            ->whereBetween('paid_at', LocalTime::periodDates('month'))
            ->sum('amount');

        $ytdRevenue = (float) Invoice::query()
            ->paid()
            ->whereBetween('paid_at', LocalTime::periodDates('year'))
            ->sum('amount');

        return [
            Stat::make('Paid This Month', Number::currency($paidThisMonth, $currency))
                ->description('Paid invoices this month')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[status][values][0]=paid'),

            Stat::make('YTD Revenue', Number::currency($ytdRevenue, $currency))
                ->description('Paid invoices this year')
                ->descriptionIcon(Heroicon::OutlinedChartBar)
                ->color('primary')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[status][values][0]=paid'),
        ];
    }
}
