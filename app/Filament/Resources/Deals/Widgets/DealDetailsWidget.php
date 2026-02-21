<?php

namespace App\Filament\Resources\Deals\Widgets;

use App\Enums\DealStatus;
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
        $deal = $this->record;

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
                ? Number::currency((float) $deal->value, $deal->currency ?: 'USD')
                : null,
            'isOverdue' => $deal->status === DealStatus::Open
                && filled($deal->expected_close_date)
                && $deal->expected_close_date->isPast(),
            'isWon' => $deal->status === DealStatus::Won,
            'isLost' => $deal->status === DealStatus::Lost,
        ];
    }
}
