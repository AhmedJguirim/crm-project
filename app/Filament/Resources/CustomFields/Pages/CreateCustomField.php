<?php

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Models\CustomField;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomField extends CreateRecord
{
    protected static string $resource = CustomFieldResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $position = $data['position'] ?? 'end';
        unset($data['position']);

        $organizationId = Filament::getTenant()->id;

        if ($position === 'beginning') {
            CustomField::where('organization_id', $organizationId)
                ->increment('order');
            $data['order'] = 1;
        } elseif (str_starts_with($position, 'after_')) {
            $afterId = (int) str_replace('after_', '', $position);
            $afterField = CustomField::where('organization_id', $organizationId)
                ->where('id', $afterId)
                ->first();

            if ($afterField) {
                $newOrder = $afterField->order + 1;
                CustomField::where('organization_id', $organizationId)
                    ->where('order', '>=', $newOrder)
                    ->increment('order');
                $data['order'] = $newOrder;
            }
        } else {
            // For 'end' position, let the model's booted method handle it
            $maxOrder = CustomField::where('organization_id', $organizationId)->max('order') ?? 0;
            $data['order'] = $maxOrder + 1;
        }

        return $data;
    }
}
