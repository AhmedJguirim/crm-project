<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Contact')
                ->modalDescription('Are you sure you want to delete this contact? This action cannot be undone.')
                ->modalSubmitActionLabel('Delete'),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['custom_fields'] = $data['custom_field_values'] ?? [];
        unset($data['custom_field_values']);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['custom_field_values'] = $data['custom_fields'] ?? [];
        unset($data['custom_fields']);

        return $data;
    }
}
