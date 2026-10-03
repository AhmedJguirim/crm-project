<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Support\CustomFields\HandlesDuplicateCustomFieldValues;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCompany extends CreateRecord
{
    use HandlesDuplicateCustomFieldValues;

    protected static string $resource = CompanyResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->haltOnDuplicateCustomFieldValue(fn (): Model => parent::handleRecordCreation($data));
    }
}
