<?php

namespace App\Filament\Resources\PipelineStages\Pages;

use App\Filament\Resources\PipelineStages\PipelineStageResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Models\PipelineStage;
use App\Services\Deals\DefaultPipeline;
use App\Services\Deals\PipelineStageOrder;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManagePipelineStages extends ManageRecords
{
    use SanitizesTableSearch;

    protected static string $resource = PipelineStageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize('create')
                ->label('New stage')
                ->modalHeading('New stage')
                ->createAnother(false)
                ->using(fn (array $data): PipelineStage => PipelineStageOrder::addOpenStage(DefaultPipeline::ensureFor(CurrentOrganization::get()), $data))
                ->successNotificationTitle('Stage added'),
        ];
    }

    /**
     * Saves the order of the open stages dragged in the table. Instead of writing the browser's order as it is, the
     * service keeps Won and Lost last, and the admin is told when a drag tried to move them up. Nothing changes when
     * the table is not reorderable for the user (Member, Viewer).
     *
     * @param  array<int, int|string>  $order
     */
    public function reorderTable(array $order, int|string|null $draggedRecordKey = null): void
    {
        if (! $this->getTable()->isReorderable()) {
            return;
        }

        $closedMoved = PipelineStageOrder::reorderOpenStages(DefaultPipeline::ensureFor(CurrentOrganization::get()), $order);

        if ($closedMoved) {
            Notification::make()
                ->warning()
                ->title('Won and Lost always stay at the end of the pipeline.')
                ->send();
        }
    }
}
