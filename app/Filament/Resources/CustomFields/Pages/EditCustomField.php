<?php

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Filament\Support\CustomFields\CustomFieldDefinitionActions;
use App\Models\CustomField;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

/**
 * @method CustomField getRecord()
 */
class EditCustomField extends EditRecord
{
    protected static string $resource = CustomFieldResource::class;

    protected Width|string|null $maxContentWidth = Width::SevenExtraLarge;

    protected function getHeaderActions(): array
    {
        return [
            CustomFieldDefinitionActions::delete('contacts'),
            CustomFieldDefinitionActions::restore('contacts'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $position = $data['position'] ?? null;
        unset($data['position']);

        if ($position && $position !== 'end') {
            $organizationId = CurrentOrganization::get()->id;
            $currentOrder = $this->getRecord()->order;

            if ($position === 'beginning') {
                $newOrder = 1;
            } elseif (str_starts_with($position, 'after_')) {
                $afterId = (int) str_replace('after_', '', $position);
                $afterField = CustomField::where('organization_id', $organizationId)
                    ->where('id', $afterId)
                    ->first();
                $newOrder = $afterField ? $afterField->order + 1 : $currentOrder;
            } else {
                $newOrder = $currentOrder;
            }

            if ($newOrder !== $currentOrder) {
                if ($newOrder < $currentOrder) {
                    CustomField::where('organization_id', $organizationId)
                        ->where('order', '>=', $newOrder)
                        ->where('order', '<', $currentOrder)
                        ->increment('order');
                } else {
                    CustomField::where('organization_id', $organizationId)
                        ->where('order', '>', $currentOrder)
                        ->where('order', '<=', $newOrder)
                        ->decrement('order');
                }

                $data['order'] = $newOrder;
            }
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $this->resequenceOrders();
    }

    protected function resequenceOrders(): void
    {
        $fields = CustomField::where('organization_id', CurrentOrganization::get()->id)
            ->orderBy('order')
            ->get();

        foreach ($fields as $index => $field) {
            $field->update(['order' => $index + 1]);
        }
    }
}
