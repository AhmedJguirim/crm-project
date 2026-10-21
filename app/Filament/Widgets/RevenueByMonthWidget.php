<?php

namespace App\Filament\Widgets;

use App\Enums\ReportPeriodPreset;
use App\Services\Reports\DashboardKpis;
use App\Support\Reports\DashboardFilters;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Dashboard grouped bar chart of the last 12 local months (the current one last): cash collected (payments minus
 * refunds of the invoice ledger, by paid_on) next to the value of the deals won (by won_at). It follows the
 * dashboard's owner filter and ignores the period filter, since it is a trend.
 */
class RevenueByMonthWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    private const int MONTHS = 12;

    private const string CASH_COLOR = '#3b82f6';

    private const string WON_COLOR = '#ea580c';

    protected static ?int $sort = -19;

    protected ?string $heading = 'Revenue by month';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    protected ?string $pollingInterval = null;

    public function getDescription(): string|Htmlable|null
    {
        return 'Cash collected (payments minus refunds) and value of deals won, per month over the last 12 months.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array{datasets: list<array{label: string, data: list<float>, backgroundColor: string, borderColor: string}>, labels: list<string>}
     */
    protected function getData(): array
    {
        $ownerId = DashboardFilters::fromPageFilters($this->pageFilters)->ownerId;
        $periods = ReportPeriod::forPreset(ReportPeriodPreset::ThisMonth)->trail(self::MONTHS);

        $cash = DashboardKpis::cashCollectedByPeriod($periods, $ownerId);
        $won = DashboardKpis::dealFiguresByPeriod($periods, $ownerId);

        return [
            'datasets' => [
                [
                    'label' => 'Cash collected',
                    'data' => array_map(fn (float $amount): float => round($amount, 2), $cash),
                    'backgroundColor' => self::CASH_COLOR,
                    'borderColor' => self::CASH_COLOR,
                ],
                [
                    'label' => 'Won deal value',
                    'data' => array_map(fn (array $figures): float => round($figures['won_value'], 2), $won),
                    'backgroundColor' => self::WON_COLOR,
                    'borderColor' => self::WON_COLOR,
                ],
            ],
            'labels' => array_map(
                fn (ReportPeriod $period): string => CarbonImmutable::createFromFormat('!Y-m-d', $period->startDate, 'UTC')->format('M Y'),
                $periods,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true]]];
    }
}
