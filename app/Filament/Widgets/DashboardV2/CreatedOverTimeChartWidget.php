<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Services\Reports\DashboardBreakdowns;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Line chart of the records created per day (per month for a period longer than three months) in the page's period,
 * for the model the subclass queries. Subclasses narrow their query by the owner filter.
 */
abstract class CreatedOverTimeChartWidget extends DashboardChartWidget
{
    /**
     * The records to count, narrowed by the owner when there is one (the period is applied by the chart).
     *
     * @return Builder<*>
     */
    abstract protected function recordsFor(?int $ownerId): Builder;

    abstract protected function seriesLabel(): string;

    abstract protected function color(): string;

    public function getDescription(): string|Htmlable|null
    {
        return $this->seriesLabel().' '.$this->filters()->period->phrase().'.';
    }

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array{datasets: list<array<string, mixed>>, labels: list<string>}
     */
    protected function getData(): array
    {
        $filters = $this->filters();
        $series = DashboardBreakdowns::createdSeries($this->recordsFor($filters->ownerId), $filters->period);
        $format = $series['unit'] === 'month' ? 'M Y' : 'M j';

        return [
            'datasets' => [[
                'label' => $this->seriesLabel(),
                'data' => array_values($series['points']),
                'borderColor' => $this->color(),
                'backgroundColor' => $this->color().'33',
                'fill' => true,
                'tension' => 0.3,
            ]],
            'labels' => array_map(
                fn (string $date): string => CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->format($format),
                array_keys($series['points']),
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
