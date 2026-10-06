<?php

namespace App\Filament\Resources\CompanyTypes\Pages;

use App\Filament\Resources\CompanyTypes\CompanyTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCompanyTypes extends ManageRecords
{
    protected static string $resource = CompanyTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
