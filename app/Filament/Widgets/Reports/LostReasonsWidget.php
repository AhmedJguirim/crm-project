<?php

namespace App\Filament\Widgets\Reports;

use App\Models\LostReason;
use App\Services\Reports\DashboardKpis;
use App\Support\Reports\DashboardFilters;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Reports page horizontal bar chart of the deals lost in the page's period and owner filters, by lost reason, next to
 * the deals lost for the same reason in the previous period. Deals without a reason are the last bar, "No reason given";
 * a deleted reason keeps its name followed by " (deleted)". Deals are counted by their current state only (a deal
 * reopened since is not counted), so the bars add up to the "Deals Lost" figure of the sales results. It is not
 * discovered onto the dashboard: only the Reports page lists it.
 */
class LostReasonsWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    private const string CURRENT_COLOR = '#dc2626';

    private const string PREVIOUS_COLOR = '#6b7280';

    private const string NO_REASON_LABEL = 'No reason given';

    protected static bool $isDiscovered = false;

    protected ?string $heading = 'Lost reasons';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    protected ?string $emptyStateHeading = 'No lost deals';

    public function getDescription(): string|Htmlable|null
    {
        $period = DashboardFilters::fromPageFilters($this->pageFilters)->period;

        return 'Deals lost '.$period->phrase().' by reason, compared with the previous period, '.$period->previous()->phrase().'. Deals reopened since are not counted.';
    }

    public function getEmptyStateDescription(): string|Htmlable|null
    {
        $period = DashboardFilters::fromPageFilters($this->pageFilters)->period;

        return 'No deals were lost '.$period->phrase().' or in the previous period.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * The reasons lost in either period (most lost deals this period first, then in the previous period, then by name)
     * and "No reason given" last, as two datasets: this period and the previous one. Empty when nothing was lost in
     * either period, which shows the empty state.
     *
     * @return array{datasets: list<array{label: string, data: list<int>, backgroundColor: string, borderColor: string}>, labels: list<string>}|array{}
     */
    protected function getData(): array
    {
        $filters = DashboardFilters::fromPageFilters($this->pageFilters);
        $period = $filters->period;

        [$previous, $current] = DashboardKpis::lostReasonCountsByPeriod($period->trail(2), $filters->ownerId);

        $reasonIds = array_values(array_unique([...array_keys($current['by_reason']), ...array_keys($previous['by_reason'])]));
        $reasons = $reasonIds === [] ? collect() : LostReason::withTrashed()->whereKey($reasonIds)->get()->keyBy('id');

        $noReason = [$current['no_reason'], $previous['no_reason']];
        $rows = [];

        foreach ($reasonIds as $reasonId) {
            $row = [
                'label' => $reasons->get($reasonId)?->lostReasonLabel(),
                'current' => $current['by_reason'][$reasonId] ?? 0,
                'previous' => $previous['by_reason'][$reasonId] ?? 0,
            ];

            if ($row['label'] === null) {
                $noReason = [$noReason[0] + $row['current'], $noReason[1] + $row['previous']];

                continue;
            }

            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b): int => $b['current'] <=> $a['current'] ?: $b['previous'] <=> $a['previous'] ?: strcmp($a['label'], $b['label']));

        if ($noReason !== [0, 0]) {
            $rows[] = ['label' => self::NO_REASON_LABEL, 'current' => $noReason[0], 'previous' => $noReason[1]];
        }

        if ($rows === []) {
            return [];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Deals Lost '.$period->title(),
                    'data' => array_column($rows, 'current'),
                    'backgroundColor' => self::CURRENT_COLOR,
                    'borderColor' => self::CURRENT_COLOR,
                ],
                [
                    'label' => 'Previous Period',
                    'data' => array_column($rows, 'previous'),
                    'backgroundColor' => self::PREVIOUS_COLOR,
                    'borderColor' => self::PREVIOUS_COLOR,
                ],
            ],
            'labels' => array_column($rows, 'label'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['indexAxis' => 'y', 'scales' => ['x' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }
}
