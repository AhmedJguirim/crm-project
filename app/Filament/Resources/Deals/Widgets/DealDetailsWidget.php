<?php

namespace App\Filament\Resources\Deals\Widgets;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

class DealDetailsWidget extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.resources.deals.widgets.deal-details-widget';

    protected int|string|array $columnSpan = 1;

    protected function getViewData(): array
    {
        /** @var Deal|null $deal */
        $deal = $this->record;
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';

        if (! $deal) {
            return [
                'formattedValue' => null,
                'isOverdue' => false,
                'isWon' => false,
                'isLost' => false,
            ];
        }

        return [
            'formattedValue' => $deal->value !== null
                ? Number::currency((float) $deal->value, $currency)
                : null,
            'isOverdue' => $deal->isOverdue(),
            'isWon' => $deal->status === DealStatus::Won,
            'isLost' => $deal->status === DealStatus::Lost,
        ];
    }
}
