<?php

namespace App\Filament\Resources\CustomFields\Schemas;

use App\Exceptions\UsedInSegmentsException;
use App\Filament\Support\CustomFields\CustomFieldDefinitionFields;
use App\Models\CustomField;
use App\Services\Segments\SegmentUsage;
use Closure;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CustomFieldForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(CustomField::class, 'name', ignoreRecord: true, modifyRuleUsing: function ($rule) {
                        return $rule->where('organization_id', Filament::getTenant()->id);
                    })
                    ->validationMessages(['unique' => CustomFieldDefinitionFields::nameTakenMessage()])
                    ->helperText('A descriptive name for this custom field'),

                CustomFieldDefinitionFields::key(),

                CustomFieldDefinitionFields::type()
                    ->disabled(fn (?CustomField $record): bool => $record !== null && SegmentUsage::isUsed($record))
                    ->helperText(fn (?CustomField $record): string => $record !== null && SegmentUsage::isUsed($record)
                        ? 'The type cannot change while this field is used in the conditions of '.SegmentUsage::describeSegments(SegmentUsage::segmentsUsing($record)).'.'
                        : 'The type of data this field will store'),

                CustomFieldDefinitionFields::options()
                    ->rule(fn (?CustomField $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($record === null) {
                            return;
                        }

                        $keptValues = collect($value)->pluck('value')->map(fn (mixed $optionValue): string => (string) $optionValue)->all();
                        $removedUsedOptions = array_diff_key(SegmentUsage::usedOptionValues($record), array_flip($keptValues));

                        if ($removedUsedOptions !== []) {
                            $fail(UsedInSegmentsException::cannotRemoveOptions($record, $removedUsedOptions)->getMessage());
                        }
                    }),

                Toggle::make('unique')
                    ->label('Unique Value')
                    ->helperText('Require unique values for this field across all contacts')
                    ->default(false),
                // TODO: make ordering more effective
                Select::make('position')
                    ->label('Position')
                    ->options(function ($record) {
                        $fields = CustomField::where('organization_id', Filament::getTenant()->id)
                            ->when($record, fn ($query) => $query->where('id', '!=', $record->id))
                            ->orderBy('order')
                            ->get();

                        $options = [
                            'beginning' => 'At the beginning',
                            'end' => 'At the end',
                        ];

                        foreach ($fields as $field) {
                            $options["after_{$field->id}"] = "After: {$field->name}";
                        }

                        return $options;
                    })
                    ->default('end')
                    ->helperText('Where to place this field in the list'),
            ]);
    }
}
