<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
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
        $paidThisMonthAggregate = Invoice::query()
            ->paid()
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount, COALESCE(COUNT(DISTINCT currency), 0) as currencies_count, COALESCE(MIN(currency), ?) as primary_currency', ['USD'])
            ->first();

        $outstandingAggregate = Invoice::query()
            ->outstanding()
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount, COALESCE(COUNT(DISTINCT currency), 0) as currencies_count, COALESCE(MIN(currency), ?) as primary_currency', ['USD'])
            ->first();

        $overdueAggregate = Invoice::query()
            ->overdue()
            ->selectRaw('COUNT(*) as invoices_count, COALESCE(SUM(amount), 0) as total_amount')
            ->first();

        $ytdAggregate = Invoice::query()
            ->paid()
            ->whereBetween('paid_at', [now()->startOfYear(), now()->endOfYear()])
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount, COALESCE(COUNT(DISTINCT currency), 0) as currencies_count, COALESCE(MIN(currency), ?) as primary_currency', ['USD'])
            ->first();

        $paidThisMonth = (float) ($paidThisMonthAggregate?->total_amount ?? 0);
        $paidThisMonthCurrency = (string) ($paidThisMonthAggregate?->primary_currency ?? 'USD');
        $paidThisMonthMixed = (int) ($paidThisMonthAggregate?->currencies_count ?? 0) > 1;

        $outstanding = (float) ($outstandingAggregate?->total_amount ?? 0);
        $outstandingCurrency = (string) ($outstandingAggregate?->primary_currency ?? 'USD');
        $outstandingMixed = (int) ($outstandingAggregate?->currencies_count ?? 0) > 1;

        $overdueCount = (int) ($overdueAggregate?->invoices_count ?? 0);
        $overdueValue = (float) ($overdueAggregate?->total_amount ?? 0);

        $ytdRevenue = (float) ($ytdAggregate?->total_amount ?? 0);
        $ytdCurrency = (string) ($ytdAggregate?->primary_currency ?? 'USD');
        $ytdMixed = (int) ($ytdAggregate?->currencies_count ?? 0) > 1;

        return [
            Stat::make('Paid This Month', Number::currency($paidThisMonth, $paidThisMonthCurrency))
                ->description($paidThisMonthMixed ? 'Mixed currencies' : 'Paid invoices this month')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[status][values][0]=paid'),

            Stat::make('Outstanding', Number::currency($outstanding, $outstandingCurrency))
                ->description($outstandingMixed ? 'Mixed currencies' : 'Sent, partial, and overdue')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('warning')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[status][values][0]=sent&tableFilters[status][values][1]=partial&tableFilters[status][values][2]=overdue'),

            Stat::make('Overdue Invoices', sprintf('%d invoice%s', $overdueCount, $overdueCount === 1 ? '' : 's'))
                ->description(Number::currency($overdueValue, $outstandingCurrency))
                ->descriptionIcon(Heroicon::OutlinedExclamationCircle)
                ->color($overdueCount > 0 ? 'danger' : 'gray')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[overdue][isActive]=1'),

            Stat::make('YTD Revenue', Number::currency($ytdRevenue, $ytdCurrency))
                ->description($ytdMixed ? 'Mixed currencies' : 'Paid invoices this year')
                ->descriptionIcon(Heroicon::OutlinedChartBar)
                ->color('primary')
                ->url(InvoiceResource::getUrl('index').'?tableFilters[status][values][0]=paid'),
        ];
    }
}
