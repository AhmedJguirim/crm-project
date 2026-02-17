<?php

namespace App\Filament\Resources\CustomFields\Pages;

use App\Filament\Resources\CustomFields\CustomFieldResource;
use App\Models\CustomField;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditCustomField extends EditRecord
{
    protected static string $resource = CustomFieldResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Custom Field')
                ->modalDescription('Are you sure you want to delete this custom field? This action cannot be undone.')
                ->modalSubmitActionLabel('Delete'),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $position = $data['position'] ?? null;
        unset($data['position']);

        if ($position && $position !== 'end') {
            $organizationId = Filament::getTenant()->id;
            $currentOrder = $this->record->order;

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
        $fields = CustomField::where('organization_id', Filament::getTenant()->id)
            ->orderBy('order')
            ->get();

        foreach ($fields as $index => $field) {
            $field->update(['order' => $index + 1]);
        }
    }
}
