<?php

namespace App\Filament\Resources\CompanyCustomFields\Pages;

use App\Filament\Resources\CompanyCustomFields\CompanyCustomFieldResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanyCustomFields extends ListRecords
{
    protected static string $resource = CompanyCustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
