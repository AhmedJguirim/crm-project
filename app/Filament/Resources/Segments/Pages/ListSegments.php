<?php

namespace App\Filament\Resources\Segments\Pages;

use App\Filament\Resources\Segments\SegmentResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use App\Models\Segment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSegments extends ListRecords
{
    use SanitizesTableSearch;

    protected static string $resource = SegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New segment')
                ->modalHeading('Create segment')
                ->createAnother(false)
                ->successRedirectUrl(fn (Segment $record): string => SegmentResource::getUrl('rules', ['record' => $record])),
        ];
    }
}
