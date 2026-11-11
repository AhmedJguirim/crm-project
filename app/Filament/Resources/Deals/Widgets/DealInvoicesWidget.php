<?php

namespace App\Filament\Resources\Deals\Widgets;

use App\Models\Invoice;
use App\Support\Reports\HomeAmount;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class DealInvoicesWidget extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.resources.deals.widgets.deal-invoices-widget';

    protected int|string|array $columnSpan = 3;

    /**
     * The invoices of the deal, and the footer totals in the home currency, each invoice at its frozen exchange rate.
     * An invoice in another currency without a rate is left out of the totals and counted in `unconvertedCount`.
     *
     * @return array{invoices: Collection<int, Invoice>, currency: string, totalAmount: float, totalPaid: float, balance: float, unconvertedCount: int}
     */
    protected function getViewData(): array
    {
        $dealId = $this->record?->getKey() ?? 0;

        $invoices = Invoice::query()
            ->where('deal_id', $dealId)
            ->orderByDesc('issued_at')
            ->get();

        $totals = Invoice::query()
            ->where('deal_id', $dealId)
            ->selectRaw(
                'COALESCE(SUM('.HomeAmount::sql('invoices.amount').'), 0) AS total_amount, '.
                'COALESCE(SUM('.HomeAmount::sql('invoices.amount_paid').'), 0) AS total_paid, '.
                'COUNT(*) FILTER (WHERE '.HomeAmount::rateSql().' IS NULL) AS unconverted_count',
                [HomeAmount::binding(), HomeAmount::binding(), HomeAmount::binding()],
            )
            ->toBase()
            ->first();

        $totalAmount = (float) ($totals->total_amount ?? 0);
        $totalPaid = (float) ($totals->total_paid ?? 0);

        return [
            'invoices' => $invoices,
            'currency' => CurrentOrganization::find()?->currencyCode() ?? 'USD',
            'totalAmount' => $totalAmount,
            'totalPaid' => $totalPaid,
            'balance' => $totalAmount - $totalPaid,
            'unconvertedCount' => (int) ($totals->unconverted_count ?? 0),
        ];
    }
}
