<?php

namespace App\Filament\Resources\Deals\Widgets;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

class DealDetailsWidget extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.resources.deals.widgets.deal-details-widget';

    protected int|string|array $columnSpan = 1;

    /**
     * Reloads the deal after a page action changed it, so the panel shows the new stage, status and dates.
     */
    #[On('dealUpdated')]
    public function refreshDeal(): void
    {
        $this->record?->refresh();
    }

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
                'timeInStage' => null,
            ];
        }

        return [
            'formattedValue' => $deal->value !== null
                ? Number::currency((float) $deal->value, $currency)
                : null,
            'isOverdue' => $deal->isOverdue(),
            'isWon' => $deal->status === DealStatus::Won,
            'isLost' => $deal->status === DealStatus::Lost,
            'timeInStage' => $this->timeInStage($deal),
        ];
    }

    /**
     * How long the deal has been in its current stage and since when (organization timezone); null when the stage
     * history doesn't know.
     */
    private function timeInStage(Deal $deal): ?string
    {
        $enteredAt = $deal->stageEnteredAt();

        if ($enteredAt === null) {
            return null;
        }

        $duration = $enteredAt->diffForHumans(now(), ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);

        return $duration.' (since '.LocalTime::format($enteredAt, 'M j, Y').')';
    }
}
