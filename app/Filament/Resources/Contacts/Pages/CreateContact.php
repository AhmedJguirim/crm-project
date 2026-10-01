<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Jobs\ResyncContactSegments;
use Filament\Resources\Pages\CreateRecord;

class CreateContact extends CreateRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * Re-evaluate segments once relationships (tags, companies) have been saved too.
     */
    protected function afterCreate(): void
    {
        ResyncContactSegments::dispatchForContacts($this->getRecord()->organization_id, [$this->getRecord()->getKey()]);
    }
}
