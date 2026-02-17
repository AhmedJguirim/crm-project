<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\CustomField;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

class ViewContact extends ViewRecord
{
    protected static string $resource = ContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('customFields')
                ->label('Custom Fields')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->schema(fn (): array => $this->buildCustomFieldsModalSchema())
                ->modalHeading('Custom Field Values')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->slideOver(),

            EditAction::make(),
        ];
    }

    /** @return array<int, mixed> */
    private function buildCustomFieldsModalSchema(): array
    {
        $record = $this->getRecord();
        $customFields = CustomField::where('organization_id', Filament::getTenant()->id)
            ->orderBy('order')
            ->get();

        if ($customFields->isEmpty()) {
            return [
                TextEntry::make('no_fields')
                    ->hiddenLabel()
                    ->state('No custom fields have been defined for this organization yet.'),
            ];
        }

        $values = $record->custom_field_values ?? [];

        $entries = $customFields->map(function (CustomField $field) use ($values): TextEntry {
            $rawValue = $values[(string) $field->id] ?? null;

            $displayValue = match (true) {
                is_array($rawValue) => $this->formatMultiselectValue($field, $rawValue),
                $field->type === 'select' && ! blank($rawValue) => $this->formatSelectValue($field, $rawValue),
                blank($rawValue) => '—',
                default => (string) $rawValue,
            };

            return TextEntry::make("custom_field_{$field->id}")
                ->label($field->name)
                ->state($displayValue);
        })->all();

        return [
            Grid::make(2)->schema($entries),
        ];
    }

    private function formatSelectValue(CustomField $field, string $value): string
    {
        $options = collect($field->options ?? [])->pluck('label', 'value');

        return $options[$value] ?? $value;
    }

    /** @param array<int, string> $values */
    private function formatMultiselectValue(CustomField $field, array $values): string
    {
        if (empty($values)) {
            return '—';
        }

        $options = collect($field->options ?? [])->pluck('label', 'value');
        $labels = collect($values)->map(fn (string $v) => $options[$v] ?? $v);

        return $labels->join(', ');
    }
}
