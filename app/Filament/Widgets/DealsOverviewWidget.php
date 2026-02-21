<?php

namespace App\Filament\Widgets;

use App\Enums\DealStage;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class DealsOverviewWidget extends BaseWidget
{
    protected ?string $pollingInterval = '30s';

    protected static ?int $sort = -30;

    protected int|string|array $columnSpan = 1;

    /**
     * @var array<string, int>|null
     */
    protected ?array $cachedOpenStageBreakdown = null;

    /**
     * @return array<string, int>
     */
    public function getOpenStageBreakdown(): array
    {
        return $this->cachedOpenStageBreakdown ??= $this->buildOpenStageBreakdown();
    }

    /**
     * @return array<string, int>
     */
    private function buildOpenStageBreakdown(): array
    {
        $countsByStage = Deal::query()
            ->open()
            ->selectRaw('stage, COUNT(*) as aggregate')
            ->groupBy('stage')
            ->pluck('aggregate', 'stage');

        return collect(DealStage::cases())
            ->mapWithKeys(function (DealStage $stage) use ($countsByStage): array {
                $count = (int) ($countsByStage[$stage->value] ?? 0);

                return $count > 0
                    ? [$stage->getLabel() => $count]
                    : [];
            })
            ->all();
    }

    public function getSectionContentComponent(): Component
    {
        return Section::make()
            ->schema([
                ...$this->getCachedStats(),

                View::make('filament.widgets.deals-stage-breakdown')
                    ->viewData([
                        'breakdown' => $this->getOpenStageBreakdown(),
                    ])
                    ->visible(fn (): bool => filled($this->getOpenStageBreakdown()))
                    ->columnSpanFull(),
            ])
            ->columns($this->getColumns())
            ->contained(false)
            ->gridContainer();
    }

    protected function getStats(): array
    {
        $openAggregate = Deal::query()
            ->open()
            ->selectRaw('COUNT(*) as deals_count, COALESCE(SUM(value), 0) as total_value, COALESCE(COUNT(DISTINCT currency), 0) as currencies_count, COALESCE(MIN(currency), ?) as primary_currency', ['USD'])
            ->first();

        $wonThisMonthAggregate = Deal::query()
            ->won()
            ->whereBetween('won_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->selectRaw('COUNT(*) as deals_count, COALESCE(SUM(value), 0) as total_value')
            ->first();

        $openDealsCount = (int) ($openAggregate?->deals_count ?? 0);
        $openPipelineValue = (float) ($openAggregate?->total_value ?? 0);
        $openCurrency = (string) ($openAggregate?->primary_currency ?? 'USD');
        $hasMixedCurrencies = (int) ($openAggregate?->currencies_count ?? 0) > 1;

        $wonDealsThisMonth = (int) ($wonThisMonthAggregate?->deals_count ?? 0);
        $wonRevenueThisMonth = (float) ($wonThisMonthAggregate?->total_value ?? 0);

        return [
            Stat::make('Open Deals', (string) $openDealsCount)
                ->description('Current open opportunities')
                ->color('primary')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=open'),

            Stat::make('Pipeline Value', Number::currency($openPipelineValue, $openCurrency))
                ->description($hasMixedCurrencies ? 'Multiple currencies in pipeline' : 'Total open pipeline value')
                ->color('primary')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=open'),

            Stat::make('Won This Month', (string) $wonDealsThisMonth)
                ->description('Deals closed as won this month')
                ->color('success')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=won'),

            Stat::make('Revenue Won This Month', Number::currency($wonRevenueThisMonth, $openCurrency))
                ->description('Won deal value this month')
                ->color('success')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=won'),
        ];
    }
}
