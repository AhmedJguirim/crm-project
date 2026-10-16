<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\Actions\TagDeletionActions;
use App\Filament\Resources\Tags\TagResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditTag extends EditRecord
{
    protected static string $resource = TagResource::class;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected function getHeaderActions(): array
    {
        return [
            TagDeletionActions::delete(),
            TagDeletionActions::restore(),
        ];
    }
}
