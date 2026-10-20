<?php

namespace App\Filament\Resources\LostReasons\Pages;

use App\Filament\Resources\LostReasons\LostReasonResource;
use App\Filament\Support\Concerns\SanitizesTableSearch;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLostReasons extends ManageRecords
{
    use SanitizesTableSearch;

    protected static string $resource = LostReasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->authorize('create')
                ->label('New reason')
                ->modalHeading('New lost reason')
                ->createAnother(false)
                ->successNotificationTitle('Lost reason added'),
        ];
    }
}
