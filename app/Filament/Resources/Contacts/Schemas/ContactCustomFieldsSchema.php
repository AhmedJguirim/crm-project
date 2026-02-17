<?php

namespace App\Filament\Resources\Contacts\Schemas;

use App\Models\Contact;
use App\Models\CustomField;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;

class ContactCustomFieldsSchema
{
    /** @return array<int, mixed> */
    public static function buildComponents(): array
    {
        $fields = CustomField::where('organization_id', Filament::getTenant()->id)
            ->orderBy('order')
            ->get();

        if ($fields->isEmpty()) {
            return [];
        }

        $components = $fields->map(function (CustomField $field) {
            $key = "custom_fields.{$field->id}";
            $component = match ($field->type) {
                'email' => TextInput::make($key)->email(),
                'url' => TextInput::make($key)->url(),
                'phone' => TextInput::make($key)->tel(),
                'number' => TextInput::make($key)->numeric(),
                'textarea' => Textarea::make($key)->rows(3),
                'date' => DatePicker::make($key),
                'select' => Select::make($key)
                    ->options(collect($field->options ?? [])->pluck('label', 'value')->toArray()),
                'multiselect' => Select::make($key)
                    ->multiple()
                    ->options(collect($field->options ?? [])->pluck('label', 'value')->toArray()),
                default => TextInput::make($key),
            };

            $component->label($field->name);

            if ($field->unique) {
                $component->rules([
                    fn (?Model $record) => function (string $attribute, mixed $value, \Closure $fail) use ($field, $record) {
                        if (blank($value)) {
                            return;
                        }

                        $exists = Contact::where('organization_id', Filament::getTenant()->id)
                            ->whereRaw('custom_field_values->>? = ?', [(string) $field->id, (string) $value])
                            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                            ->exists();

                        if ($exists) {
                            $fail("The {$field->name} must be unique.");
                        }
                    },
                ]);
            }

            return $component;
        })->all();

        return [
            Section::make('Additional Information')
                ->collapsible()
                ->schema($components),
        ];
    }
}
