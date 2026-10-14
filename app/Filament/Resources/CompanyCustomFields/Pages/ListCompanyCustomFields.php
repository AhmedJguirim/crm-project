<?php

namespace App\Filament\Resources\CompanyCustomFields\Pages;

use App\Filament\Resources\CompanyCustomFields\CompanyCustomFieldResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCompanyCustomFields extends ListRecords
{
    use SanitizesTableSearch;

    protected static string $resource = CompanyCustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
