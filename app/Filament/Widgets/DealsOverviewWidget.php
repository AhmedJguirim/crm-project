<?php

namespace App\Filament\Widgets;

use App\Enums\DealStatus;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Support\LocalTime;
use App\Support\Tenancy\CurrentOrganization;
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
            ->selectRaw('pipeline_stage_id, COUNT(*) as aggregate')
            ->groupBy('pipeline_stage_id')
            ->pluck('aggregate', 'pipeline_stage_id');

        return PipelineStage::query()
            ->where('type', DealStatus::Open)
            ->orderBy('position')
            ->pluck('name', 'id')
            ->mapWithKeys(function (string $name, int $id) use ($countsByStage): array {
                $count = (int) ($countsByStage[$id] ?? 0);

                return $count > 0 ? [$name => $count] : [];
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
        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';

        $openAggregate = Deal::query()
            ->open()
            ->selectRaw('COUNT(*) as deals_count, COALESCE(SUM(value), 0) as total_value')
            ->first();

        $wonThisMonthAggregate = Deal::query()
            ->won()
            ->whereBetween('won_at', LocalTime::period('month'))
            ->selectRaw('COUNT(*) as deals_count, COALESCE(SUM(value), 0) as total_value')
            ->first();

        $openDealsCount = (int) ($openAggregate?->getAttribute('deals_count') ?? 0);
        $openPipelineValue = (float) ($openAggregate?->getAttribute('total_value') ?? 0);

        $wonDealsThisMonth = (int) ($wonThisMonthAggregate?->getAttribute('deals_count') ?? 0);
        $wonRevenueThisMonth = (float) ($wonThisMonthAggregate?->getAttribute('total_value') ?? 0);

        return [
            Stat::make('Open Deals', (string) $openDealsCount)
                ->description('Current open opportunities')
                ->color('primary')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=open'),

            Stat::make('Pipeline Value', Number::currency($openPipelineValue, $currency))
                ->description('Total open pipeline value')
                ->color('primary')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=open'),

            Stat::make('Won This Month', (string) $wonDealsThisMonth)
                ->description('Deals closed as won this month')
                ->color('success')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=won'),

            Stat::make('Revenue Won This Month', Number::currency($wonRevenueThisMonth, $currency))
                ->description('Won deal value this month')
                ->color('success')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=won'),
        ];
    }
}
