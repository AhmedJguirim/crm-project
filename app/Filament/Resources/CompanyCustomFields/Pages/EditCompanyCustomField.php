<?php

namespace App\Filament\Resources\CompanyCustomFields\Pages;

use App\Filament\Resources\CompanyCustomFields\CompanyCustomFieldResource;
use App\Filament\Support\CustomFields\CustomFieldDefinitionActions;
use App\Models\CompanyCustomField;
use Filament\Resources\Pages\EditRecord;

class EditCompanyCustomField extends EditRecord
{
    protected static string $resource = CompanyCustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CustomFieldDefinitionActions::delete('companies'),
            CustomFieldDefinitionActions::restore('companies'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $position = $data['position'] ?? null;
        unset($data['position']);

        if (! $position || $position === 'end') {
            return $data;
        }

        $siblings = CompanyCustomField::query()->where('company_type_id', $this->record->company_type_id);
        $currentOrder = $this->record->order;

        $newOrder = $currentOrder;

        if ($position === 'beginning') {
            $newOrder = 1;
        } elseif (str_starts_with($position, 'after_')) {
            $afterField = (clone $siblings)->find((int) str_replace('after_', '', $position));
            $newOrder = $afterField ? $afterField->order + 1 : $currentOrder;
        }

        if ($newOrder === $currentOrder) {
            return $data;
        }

        if ($newOrder < $currentOrder) {
            (clone $siblings)
                ->where('order', '>=', $newOrder)
                ->where('order', '<', $currentOrder)
                ->increment('order');
        } else {
            (clone $siblings)
                ->where('order', '>', $currentOrder)
                ->where('order', '<=', $newOrder)
                ->decrement('order');
        }

        $data['order'] = $newOrder;

        return $data;
    }

    protected function afterSave(): void
    {
        CompanyCustomField::query()
            ->where('company_type_id', $this->record->company_type_id)
            ->orderBy('order')
            ->get()
            ->each(fn (CompanyCustomField $field, int $index) => $field->update(['order' => $index + 1]));
    }
}
