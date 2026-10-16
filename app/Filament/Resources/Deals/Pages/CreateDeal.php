<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Schemas\DealForm;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return DealForm::mutateDataBeforeCreate($data);
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
