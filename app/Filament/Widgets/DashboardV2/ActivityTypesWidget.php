<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Enums\ActivityType;
use App\Models\Activity;
use App\Services\Reports\DashboardBreakdowns;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard doughnut of the activities logged in the page's period (by occurred_at), by type. The owner filter
 * applies to the user who logged the activity.
 */
class ActivityTypesWidget extends DashboardChartWidget
{
    protected ?string $heading = 'Activity type breakdown';

    protected ?string $emptyStateHeading = 'No activities';

    public function getDescription(): string|Htmlable|null
    {
        return 'Activities logged '.$this->filters()->period->phrase().', by type.';
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $filters = $this->filters();

        $counts = DashboardBreakdowns::countsBy(
            Activity::query()
                ->whereBetween('occurred_at', $filters->period->bounds())
                ->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('user_id', $filters->ownerId)),
            'type',
        );

        $labelled = [];

        foreach ($counts as $stored => $count) {
            $label = ActivityType::tryFrom($stored)?->getLabel() ?? 'Other';
            $labelled[$label] = ($labelled[$label] ?? 0) + $count;
        }

        return $this->categories($labelled, 'Activities');
    }

    protected function getOptions(): array
    {
        return $this->doughnutOptions();
    }
}
