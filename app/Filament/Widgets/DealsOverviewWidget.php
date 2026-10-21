<?php

namespace App\Filament\Widgets;

use App\Enums\DealStatus;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Models\PipelineStage;
use App\Support\Reports\DashboardFilters;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Dashboard deal figures. The page's owner filter applies to every figure and its period to the two "Won" figures.
 */
class DealsOverviewWidget extends BaseWidget
{
    use InteractsWithPageFilters;

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
     * The dashboard filters, validated (see DashboardFilters).
     */
    protected function dashboardFilters(): DashboardFilters
    {
        return DashboardFilters::fromPageFilters($this->pageFilters);
    }

    /**
     * @return array<string, int>
     */
    private function buildOpenStageBreakdown(): array
    {
        $ownerId = $this->dashboardFilters()->ownerId;

        $countsByStage = Deal::query()
            ->open()
            ->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId))
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
        $this->cachedOpenStageBreakdown = null;

        $currency = CurrentOrganization::find()?->currencyCode() ?? 'USD';
        $filters = $this->dashboardFilters();
        $period = $filters->period;

        $openAggregate = Deal::query()
            ->open()
            ->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId))
            ->selectRaw('COUNT(*) as deals_count, COALESCE(SUM(value), 0) as total_value')
            ->first();

        $wonAggregate = Deal::query()
            ->won()
            ->when($filters->ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $filters->ownerId))
            ->whereBetween('won_at', $period->bounds())
            ->selectRaw('COUNT(*) as deals_count, COALESCE(SUM(value), 0) as total_value')
            ->first();

        $openDealsCount = (int) ($openAggregate?->getAttribute('deals_count') ?? 0);
        $openPipelineValue = (float) ($openAggregate?->getAttribute('total_value') ?? 0);

        $wonDealsInPeriod = (int) ($wonAggregate?->getAttribute('deals_count') ?? 0);
        $wonRevenueInPeriod = (float) ($wonAggregate?->getAttribute('total_value') ?? 0);

        return [
            Stat::make('Open Deals', (string) $openDealsCount)
                ->description('Current open opportunities')
                ->color('primary')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=open'),

            Stat::make('Pipeline Value', Number::currency($openPipelineValue, $currency))
                ->description('Total open pipeline value')
                ->color('primary')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=open'),

            Stat::make('Won '.$period->title(), (string) $wonDealsInPeriod)
                ->description('Deals closed as won '.$period->phrase())
                ->color('success')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=won'),

            Stat::make('Revenue Won '.$period->title(), Number::currency($wonRevenueInPeriod, $currency))
                ->description('Won deal value '.$period->phrase())
                ->color('success')
                ->url(DealResource::getUrl('index').'?tableFilters[status][value]=won'),
        ];
    }
}
