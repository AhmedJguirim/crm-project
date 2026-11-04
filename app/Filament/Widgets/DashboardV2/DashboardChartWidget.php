<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Support\Reports\DashboardFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The base of the Dashboard charts: they follow the page's period and owner filters (validated through
 * `DashboardFilters`), are half a row wide, never poll, and are not discovered by the panel, only the Dashboard
 * page lists them. A chart whose `getData()` is empty shows its empty state.
 */
abstract class DashboardChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    /**
     * Colors of the slices and bars of the charts that have one per category.
     *
     * @var list<string>
     */
    protected const array PALETTE = ['#3b82f6', '#ea580c', '#16a34a', '#a855f7', '#eab308', '#06b6d4', '#ec4899', '#64748b', '#ef4444'];

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    public function getEmptyStateDescription(): string|Htmlable|null
    {
        return 'Nothing to show '.$this->filters()->period->phrase().'.';
    }

    protected function filters(): DashboardFilters
    {
        return DashboardFilters::fromPageFilters($this->pageFilters);
    }

    /**
     * One dataset with a color per category, from the non-zero counts (biggest first when `$sorted`); empty when
     * nothing is counted, which shows the empty state. A category without an entry in `$colorsByLabel` takes the next
     * color of the palette.
     *
     * @param  array<string, int>  $counts  the count of each label
     * @param  array<string, string>  $colorsByLabel
     * @return array{datasets: list<array{label: string, data: list<int>, backgroundColor: list<string>}>, labels: list<string>}|array{}
     */
    protected function categories(array $counts, string $datasetLabel, bool $sorted = true, array $colorsByLabel = []): array
    {
        $counts = array_filter($counts);

        if ($counts === []) {
            return [];
        }

        if ($sorted) {
            arsort($counts);
        }

        $backgrounds = [];

        foreach (array_keys($counts) as $index => $label) {
            $backgrounds[] = $colorsByLabel[$label] ?? self::PALETTE[$index % count(self::PALETTE)];
        }

        return [
            'datasets' => [['label' => $datasetLabel, 'data' => array_values($counts), 'backgroundColor' => $backgrounds]],
            'labels' => array_keys($counts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function doughnutOptions(): array
    {
        return [
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function horizontalBarOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
