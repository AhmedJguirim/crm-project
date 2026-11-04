<?php

namespace App\Filament\Widgets\DashboardV2;

use App\Models\Deal;
use App\Models\PipelineStage;
use App\Services\Reports\DashboardBreakdowns;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard bar chart of the deals open now, per pipeline stage (owner filter on the deal owner). It is a snapshot:
 * the period filter does not apply. Stages follow the pipeline order, and carry their pipeline's name when the deals
 * sit in more than one pipeline.
 */
class DealsByStageWidget extends DashboardChartWidget
{
    protected ?string $heading = 'Deals by stage';

    protected ?string $emptyStateHeading = 'No open deals';

    public function getDescription(): string|Htmlable|null
    {
        return 'Deals open now, by pipeline stage.';
    }

    public function getEmptyStateDescription(): string|Htmlable|null
    {
        return 'There is no open deal.';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $ownerId = $this->filters()->ownerId;

        $counts = DashboardBreakdowns::countsBy(
            Deal::query()->open()->when($ownerId !== null, fn (Builder $query): Builder => $query->where('owner_id', $ownerId)),
            'pipeline_stage_id',
        );

        $stages = PipelineStage::query()
            ->with('pipeline')
            ->whereKey(array_keys($counts))
            ->orderBy('pipeline_id')
            ->orderBy('position')
            ->get();

        $showPipeline = $stages->pluck('pipeline_id')->unique()->count() > 1;
        $labelled = [];

        foreach ($stages as $stage) {
            $labelled[($showPipeline ? $stage->pipeline?->name.' · ' : '').$stage->name] = $counts[(string) $stage->getKey()];
        }

        $data = $this->categories($labelled, 'Open deals', sorted: false);

        if ($data !== []) {
            $data['datasets'][0]['backgroundColor'] = self::PALETTE[0];
        }

        return $data;
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }
}
