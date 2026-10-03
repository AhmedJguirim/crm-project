<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\CustomFields\HandlesDuplicateCustomFieldValues;
use App\Jobs\ResyncContactSegments;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditContact extends EditRecord
{
    use HandlesDuplicateCustomFieldValues;

    protected static string $resource = ContactResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->haltOnDuplicateCustomFieldValue(fn (): Model => parent::handleRecordUpdate($record, $data));
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Contact')
                ->modalDescription('Are you sure you want to delete this contact? You can restore it later.')
                ->modalSubmitActionLabel('Delete'),
            RestoreAction::make(),
        ];
    }

    /**
     * Re-evaluate segments once relationships (tags, companies) have been saved too. ContactObserver already queued a
     * resync when the contact row was saved; ResyncContactSegments is unique per contact until processed, so this
     * second dispatch only matters when the first one already ran.
     */
    protected function afterSave(): void
    {
        ResyncContactSegments::dispatchForContacts($this->getRecord()->organization_id, [$this->getRecord()->getKey()]);
    }
}
