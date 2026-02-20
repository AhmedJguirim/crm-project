<?php

namespace App\Filament\Resources\Tasks\Pages;

use App\Enums\TaskStatus;
use App\Filament\Resources\Tasks\TaskResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTask extends CreateRecord
{
    protected static string $resource = TaskResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] ??= TaskStatus::Pending;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return TaskResource::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Task created successfully';
    }
}
