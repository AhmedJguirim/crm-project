<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use App\Filament\Support\Concerns\ResolvesReadableTableRecordKeys;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTags extends ListRecords
{
    use ResolvesReadableTableRecordKeys;
    use SanitizesTableSearch;

    protected static string $resource = TagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
