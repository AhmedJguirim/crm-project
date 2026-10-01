<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Jobs\ResyncContactSegments;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
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
                ->modalDescription('Are you sure you want to delete this contact? You can restore it later.')
                ->modalSubmitActionLabel('Delete'),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Re-evaluate segments once relationships (tags, companies) have been saved too.
     */
    protected function afterSave(): void
    {
        ResyncContactSegments::dispatchForContacts($this->getRecord()->organization_id, [$this->getRecord()->getKey()]);
    }
}
