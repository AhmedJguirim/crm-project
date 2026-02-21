<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Deals\DealResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $stage = $data['stage'] instanceof DealStage
            ? $data['stage']
            : DealStage::from($data['stage']);

        if ($stage === DealStage::Won) {
            $data['status'] = DealStatus::Won;
            $data['won_at'] ??= now();
            $data['lost_at'] = null;
        } elseif ($stage === DealStage::Lost) {
            $data['status'] = DealStatus::Lost;
            $data['lost_at'] ??= now();
            $data['won_at'] = null;
        } else {
            $data['status'] = DealStatus::Open;
            $data['won_at'] = null;
            $data['lost_at'] = null;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
