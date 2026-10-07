<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Facades\Filament;
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
        $currency = Filament::getTenant()?->currencyCode() ?? 'USD';

        $paidThisMonth = (float) Invoice::query()
            ->paid()
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('amount');

        $outstanding = (float) Invoice::query()
            ->outstanding()
            ->sum('amount');

        $overdueAggregate = Invoice::query()
            ->overdue()
            ->selectRaw('COUNT(*) as invoices_count, COALESCE(SUM(amount), 0) as total_amount')
            ->first();

        $ytdRevenue = (float) Invoice::query()
            ->paid()
            ->whereBetween('paid_at', [now()->startOfYear(), now()->endOfYear()])
            ->sum('amount');

        $overdueCount = (int) ($overdueAggregate?->invoices_count ?? 0);
        $overdueValue = (float) ($overdueAggregate?->total_amount ?? 0);

        return [
            Stat::make('Paid This Month', Number::currency($paidThisMonth, $currency))
                ->description('Paid invoices this month')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[status][values][0]=paid'),

            Stat::make('Outstanding', Number::currency($outstanding, $currency))
                ->description('Sent, partial, and overdue')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('warning')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[status][values][0]=sent&tableFilters[status][values][1]=partial&tableFilters[status][values][2]=overdue'),

            Stat::make('Overdue Invoices', sprintf('%d invoice%s', $overdueCount, $overdueCount === 1 ? '' : 's'))
                ->description(Number::currency($overdueValue, $currency))
                ->descriptionIcon(Heroicon::OutlinedExclamationCircle)
                ->color($overdueCount > 0 ? 'danger' : 'gray')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[overdue][isActive]=1'),

            Stat::make('YTD Revenue', Number::currency($ytdRevenue, $currency))
                ->description('Paid invoices this year')
                ->descriptionIcon(Heroicon::OutlinedChartBar)
                ->color('primary')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[status][values][0]=paid'),
        ];
    }
}
