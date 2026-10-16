<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Support\CustomFields\HandlesDuplicateCustomFieldValues;
use App\Filament\Support\SegmentUsageGuard;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

class EditCompany extends EditRecord
{
    use HandlesDuplicateCustomFieldValues;

    protected static string $resource = CompanyResource::class;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return $this->haltOnDuplicateCustomFieldValue(fn (): Model => parent::handleRecordUpdate($record, $data));
    }

    protected function getHeaderActions(): array
    {
        return [
            SegmentUsageGuard::protectDelete(DeleteAction::make()),
            RestoreAction::make(),
        ];
    }
}
