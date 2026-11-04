<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Services\Reports\TeamActivity;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Dashboard bar chart of the value of the deals won in the page's period, per deal owner (the figures of the team
 * leaderboard, see TeamActivity). Owners without a won deal are left out.
 */
class OwnerPerformanceWidget extends DashboardChartWidget
{
    protected ?string $heading = 'Deal owner performance by closed amount';

    protected ?string $emptyStateHeading = 'No won deals';

    public function getDescription(): string|Htmlable|null
    {
        return 'Value of the deals won '.$this->filters()->period->phrase().', per deal owner.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $filters = $this->filters();
        $labels = [];
        $values = [];

        foreach (TeamActivity::forPeriod($filters->period, $filters->ownerId) as $row) {
            if ($row['is_total'] || $row['won_value'] <= 0) {
                continue;
            }

            $labels[] = $row['member'];
            $values[] = round($row['won_value'], 2);
        }

        if ($labels === []) {
            return [];
        }

        return [
            'datasets' => [['label' => 'Won value ('.(CurrentOrganization::find()?->currencyCode() ?? 'USD').')', 'data' => $values, 'backgroundColor' => self::PALETTE[0]]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true]],
        ];
    }
}
