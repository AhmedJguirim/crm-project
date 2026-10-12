<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Services\Deals\DealStageMover;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = Filament::getTenant()->id;
        $data['created_by'] = auth()->id();

        return [...$data, ...DealStageMover::attributesFor($data['stage'])];
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
