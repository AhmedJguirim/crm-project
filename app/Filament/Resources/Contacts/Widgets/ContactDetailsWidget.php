<?php

namespace App\Filament\Resources\Contacts\Widgets;

use App\Models\Contact;
use App\Models\CustomField;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ContactDetailsWidget extends Widget
{
    public ?Model $record = null;

    protected string $view = 'filament.resources.contacts.widgets.contact-details-widget';

    protected int|string|array $columnSpan = 1;

    protected function getViewData(): array
    {
        if (! $this->record instanceof Contact) {
            return ['customFields' => collect(), 'segments' => collect()];
        }

        $resolvedFields = $this->record->customFieldDefinitions()
            ->map(fn (CustomField $field): array => [
                'label' => $field->name,
                'value' => $field->formatValue($this->record->customFieldValue($field->key)),
            ])
            ->filter(fn (array $field): bool => $field['value'] !== null);

        return [
            'customFields' => $resolvedFields,
            'segments' => $this->record->segments()->orderBy('name')->get(),
        ];
    }
}
