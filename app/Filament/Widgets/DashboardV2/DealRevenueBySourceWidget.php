<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Services\Reports\LeadSources;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Dashboard bar chart of the value of the deals won in the page's period, by the lead source of their contact (the
 * figures of the Reports page, see LeadSources). Sources without won value are left out.
 */
class DealRevenueBySourceWidget extends DashboardChartWidget
{
    protected ?string $heading = 'Deal revenue by lead source';

    protected ?string $emptyStateHeading = 'No won deals';

    public function getDescription(): string|Htmlable|null
    {
        return 'Value of the deals won '.$this->filters()->period->phrase().', by lead source of the contact.';
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

        foreach (LeadSources::forPeriod($filters->period, $filters->ownerId) as $row) {
            if ($row['is_total'] || $row['won_value'] <= 0) {
                continue;
            }

            $labels[] = $row['source'];
            $values[] = round($row['won_value'], 2);
        }

        if ($labels === []) {
            return [];
        }

        return [
            'datasets' => [['label' => 'Won value', 'data' => $values, 'backgroundColor' => self::PALETTE[1]]],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
