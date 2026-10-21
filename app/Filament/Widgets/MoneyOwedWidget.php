<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\Reports\Receivables;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * The unpaid balance of the organization as of today and how late it is. It does not follow the dashboard period and
 * owner filters: a balance is a position today, and invoices have no owner.
 */
class MoneyOwedWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected static ?int $sort = -21;

    protected int|string|array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return 'Money owed';
    }

    protected function getDescription(): ?string
    {
        return 'Unpaid invoices of the whole organization as of today, by how late they are.';
    }

    protected function getStats(): array
    {
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';
        $aging = Receivables::aging(LocalTime::todayDate());

        $money = fn (float $amount): string => Number::currency($amount, $currency);
        $invoices = fn (int $count): string => $count.' '.($count === 1 ? 'invoice' : 'invoices');

        $unpaidCount = array_sum(array_column($aging, 'count'));
        $unpaidBalance = array_sum(array_column($aging, 'balance'));
        $lateBalance = $unpaidBalance - $aging['not_due']['balance'];

        $listUrl = InvoiceResource::getUrl('index');
        $lateUrl = $listUrl.'?tableFilters[overdue][isActive]=1';

        $stats = [
            Stat::make('Outstanding', $money($unpaidBalance))
                ->description($unpaidCount === 0
                    ? 'No unpaid invoices'
                    : $unpaidCount.' unpaid '.($unpaidCount === 1 ? 'invoice' : 'invoices').' · '.$money($lateBalance).' overdue')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color('warning')
                ->url($listUrl.'?tableFilters[status][values][0]=sent&tableFilters[status][values][1]=partial&tableFilters[status][values][2]=overdue'),

            Stat::make('Not due yet', $money($aging['not_due']['balance']))
                ->description($invoices($aging['not_due']['count']))
                ->descriptionIcon(Heroicon::OutlinedCalendar)
                ->color('gray'),
        ];

        $lateLabels = [
            'days_1_30' => '1–30 days late',
            'days_31_60' => '31–60 days late',
            'days_61_90' => '61–90 days late',
            'over_90' => 'Over 90 days late',
        ];

        foreach ($lateLabels as $bucket => $label) {
            $count = $aging[$bucket]['count'];

            $stat = Stat::make($label, $money($aging[$bucket]['balance']))
                ->description($invoices($count))
                ->descriptionIcon(Heroicon::OutlinedExclamationCircle)
                ->color($count > 0 ? 'danger' : 'gray');

            if ($count > 0) {
                $stat->url($lateUrl);
            }

            $stats[] = $stat;
        }

        return $stats;
    }
}
