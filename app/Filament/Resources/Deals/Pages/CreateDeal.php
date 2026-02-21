<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Deals\DealResource;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = Filament::getTenant()->id;
        $data['created_by'] = auth()->id();

        $stage = $data['stage'] instanceof DealStage
            ? $data['stage']
            : DealStage::from($data['stage']);

        if ($stage === DealStage::Won) {
            $data['status'] = DealStatus::Won;
            $data['won_at'] = now();
            $data['lost_at'] = null;
        } elseif ($stage === DealStage::Lost) {
            $data['status'] = DealStatus::Lost;
            $data['lost_at'] = now();
            $data['won_at'] = null;
        } else {
            $data['status'] = DealStatus::Open;
            $data['won_at'] = null;
            $data['lost_at'] = null;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return DealResource::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Deal created successfully';
    }
}
