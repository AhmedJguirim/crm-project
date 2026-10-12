<?php

namespace App\Filament\Resources\Deals\Widgets;

use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class DealInvoicesWidget extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.resources.deals.widgets.deal-invoices-widget';

    protected int|string|array $columnSpan = 3;

    protected function getViewData(): array
    {
        $invoices = Invoice::query()
            ->where('deal_id', $this->record?->getKey() ?? 0)
            ->orderByDesc('issued_at')
            ->get();

        return [
            'invoices' => $invoices,
            'currency' => Filament::getTenant()?->currencyCode() ?? 'USD',
        ];
    }
}
