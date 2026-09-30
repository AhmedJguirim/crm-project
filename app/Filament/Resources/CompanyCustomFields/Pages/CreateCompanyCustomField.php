<?php

namespace App\Filament\Resources\CompanyCustomFields\Pages;

use App\Filament\Resources\CompanyCustomFields\CompanyCustomFieldResource;
use App\Models\CompanyCustomField;
use Filament\Resources\Pages\CreateRecord;

class CreateCompanyCustomField extends CreateRecord
{
    protected static string $resource = CompanyCustomFieldResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $position = $data['position'] ?? 'end';
        unset($data['position']);

        $siblings = CompanyCustomField::query()->where('company_type_id', $data['company_type_id']);

        if ($position === 'beginning') {
            $siblings->increment('order');
            $data['order'] = 1;

            return $data;
        }

        if (str_starts_with($position, 'after_')) {
            $afterField = (clone $siblings)->find((int) str_replace('after_', '', $position));

            if ($afterField) {
                $newOrder = $afterField->order + 1;
                (clone $siblings)->where('order', '>=', $newOrder)->increment('order');
                $data['order'] = $newOrder;

                return $data;
            }
        }

        $data['order'] = ($siblings->max('order') ?? 0) + 1;

        return $data;
    }
}
