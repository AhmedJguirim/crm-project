<?php

namespace App\Filament\Resources\Contacts\Widgets;

use App\Models\CustomField;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ContactDetailsWidget extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.resources.contacts.widgets.contact-details-widget';

    protected int|string|array $columnSpan = 1;

    protected function getViewData(): array
    {
        $customFields = CustomField::where('organization_id', Filament::getTenant()->id)
            ->orderBy('order')
            ->get();

        $values = $this->record?->custom_field_values ?? [];

        $resolvedFields = $customFields->map(function (CustomField $field) use ($values): array {
            $rawValue = $values[(string) $field->id] ?? null;

            $displayValue = match (true) {
                is_array($rawValue) => $this->formatMultiselectValue($field, $rawValue),
                $field->type === 'select' && ! blank($rawValue) => $this->formatSelectValue($field, $rawValue),
                blank($rawValue) => null,
                default => (string) $rawValue,
            };

            return [
                'label' => $field->name,
                'value' => $displayValue,
            ];
        })->filter(fn (array $field): bool => $field['value'] !== null);

        return [
            'customFields' => $resolvedFields,
        ];
    }

    private function formatSelectValue(CustomField $field, string $value): string
    {
        $options = collect($field->options ?? [])->pluck('label', 'value');

        return $options[$value] ?? $value;
    }

    /** @param array<int, string> $values */
    private function formatMultiselectValue(CustomField $field, array $values): ?string
    {
        if (empty($values)) {
            return null;
        }

        $options = collect($field->options ?? [])->pluck('label', 'value');
        $labels = collect($values)->map(fn (string $v) => $options[$v] ?? $v);

        return $labels->join(', ');
    }
}
