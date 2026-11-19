<?php

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Support\Concerns\ResolvesReadableTableRecordKeys;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustomFields extends ListRecords
{
    use ResolvesReadableTableRecordKeys;
    use SanitizesTableSearch;

    protected static string $resource = CustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
