<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

/**
 * @method Deal getRecord()
 */
class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return [...$data, ...DealStageMover::attributesFor(DefaultPipeline::stageById($this->getRecord()->organization_id, $data['pipeline_stage_id']), $this->getRecord())];
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
