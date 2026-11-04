<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\DealStatus;
use App\Models\Deal;
use App\Services\Reports\DashboardBreakdowns;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard doughnut of the deals created in the page's period, by the status they have now (open, won, lost).
 * The owner filter applies to the deal owner.
 */
class DealsByStatusWidget extends DashboardChartWidget
{
    protected ?string $heading = 'Deals by status';

    protected ?string $emptyStateHeading = 'No new deals';

    public function getDescription(): string|Htmlable|null
    {
        return 'Deals created '.$this->filters()->period->phrase().', by the status they have now.';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $filters = $this->filters();

        $counts = DashboardBreakdowns::countsBy(
            Deal::query()
                ->whereBetween('created_at', $filters->period->bounds())
                ->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId)),
            'status',
        );

        $ordered = [];

        foreach (DealStatus::cases() as $status) {
            $ordered[$status->getLabel()] = $counts[$status->value] ?? 0;
        }

        return $this->categories($ordered, 'Deals', sorted: false, colorsByLabel: [
            DealStatus::Open->getLabel() => '#64748b',
            DealStatus::Won->getLabel() => '#16a34a',
            DealStatus::Lost->getLabel() => '#ef4444',
        ]);
    }

    protected function getOptions(): array
    {
        return $this->doughnutOptions();
    }
}
