<?php

namespace App\Support\Reports;

use App\Filament\Resources\Invoices\InvoiceResource;

/**
 * The invoice list URL showing the invoices behind the dashboard "Cash Collected" stat: those with a payment or refund
 * dated in the stat's period and, when the page owner filter is set, created by that owner (BUG-75). Built by hand:
 * `http_build_query` would percent-encode the brackets.
 */
final class InvoiceListLink
{
    public static function cashCollected(DashboardFilters $filters): string
    {
        [$startDate, $endDate] = $filters->period->dates();

        return self::withOwner(
            InvoiceResource::getUrl('index').'?filters[paid_between][from]='.$startDate.'&filters[paid_between][until]='.$endDate,
            $filters,
        );
    }

    private static function withOwner(string $url, DashboardFilters $filters): string
    {
        if ($filters->ownerId === null) {
            return $url;
        }

        return $url.'&filters[created_by][value]='.$filters->ownerId;
    }
}
