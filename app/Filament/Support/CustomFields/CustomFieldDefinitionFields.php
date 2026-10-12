<?php

namespace App\Filament\Support\CustomFields;

use App\Models\CompanyCustomField;
use App\Models\CustomField;
use App\Support\CustomFields\OptionUsage;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Form components shared by the contact and company custom field definition forms.
 */
class CustomFieldDefinitionFields
{
    private const SEMICOLON_MESSAGE = 'Option names can\'t contain ";": it separates several values in imports.';

    /** @var array<string, string> */
    public const TYPES = [
        'text' => 'Text',
        'email' => 'Email',
        'url' => 'URL',
        'phone' => 'Phone',
        'number' => 'Number',
        'date' => 'Date',
        'textarea' => 'Text Area',
        'select' => 'Select (Dropdown)',
        'multiselect' => 'Multi-Select',
    ];

    /** @var array<int, string> */
    public const TYPES_WITH_OPTIONS = ['select', 'multiselect'];

    public static function nameTakenMessage(): string
    {
        return 'A custom field with this name already exists. It may be a deleted one: '
            .'check the "Trashed" filter of the list and restore it instead of creating a new field.';
    }

    public static function key(): TextInput
    {
        return TextInput::make('key')
            ->label('Key')
            ->disabled()
            ->dehydrated(false)
            ->visibleOn('edit')
            ->helperText('Random identifier generated on creation. It never changes, even if the field is renamed.');
    }

    public static function type(): Select
    {
        return Select::make('type')
            ->required()
            ->options(self::TYPES)
            ->live()
            ->disabled(fn (?Model $record): bool => $record !== null)
            ->helperText(fn (?Model $record): string => $record !== null
                ? "The type can't change after the field is created. To store another kind of data, create a new field."
                : 'The type of data this field will store');
    }

    public static function options(): Repeater
    {
        return Repeater::make('options')
            ->table([TableColumn::make('Option')->hiddenHeaderLabel()])
            ->schema([
                TextInput::make('label')
                    ->hiddenLabel()
                    ->placeholder('Option name')
                    ->required()
                    ->maxLength(255)
                    ->notRegex('/;/')
                    ->validationMessages(['not_regex' => self::SEMICOLON_MESSAGE]),

                Hidden::make('value'),
            ])
            ->visible(fn (Get $get): bool => in_array($get('type'), self::TYPES_WITH_OPTIONS))
            ->required(fn (Get $get): bool => in_array($get('type'), self::TYPES_WITH_OPTIONS))
            ->minItems(1)
            ->defaultItems(0)
            ->addActionLabel('Add option')
            ->reorderable()
            ->reorderableWithDragAndDrop()
            ->reorderableWithButtons(false)
            ->hintAction(self::pasteSeveralOptionsAction())
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                $labels = collect($value)->map(fn (mixed $option): string => self::normalizedName($option['label'] ?? ''))->filter();

                if ($labels->count() !== $labels->unique()->count()) {
                    $fail('Each option needs a different name.');
                }
            })
            ->helperText('The options users can pick. Renaming an option is safe: contacts keep it.');
    }

    /**
     * Adds one option per line of a pasted text, skipping the names the field already has. Nothing is saved until the
     * form is.
     */
    protected static function pasteSeveralOptionsAction(): Action
    {
        return Action::make('pasteSeveralOptions')
            ->label('Paste several')
            ->modalHeading('Paste several options')
            ->modalSubmitActionLabel('Add options')
            ->schema([
                Textarea::make('lines')
                    ->label('Options')
                    ->helperText('One option per line.')
                    ->rows(8)
                    ->required()
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        $line = collect(self::pastedLines((string) $value))->first(fn (string $line): bool => str_contains($line, ';'));

                        if ($line !== null) {
                            $fail("\"{$line}\": ".self::SEMICOLON_MESSAGE);
                        }
                    }),
            ])
            ->action(function (array $data, Get $get, Set $set): void {
                $options = $get('options') ?? [];
                $names = collect($options)->map(fn (array $option): string => self::normalizedName($option['label'] ?? ''))->filter()->all();
                $added = 0;
                $skipped = 0;

                foreach (self::pastedLines((string) $data['lines']) as $line) {
                    if (in_array(self::normalizedName($line), $names, true)) {
                        $skipped++;

                        continue;
                    }

                    $names[] = self::normalizedName($line);
                    $options[(string) Str::uuid()] = ['label' => $line, 'value' => null];
                    $added++;
                }

                $set('options', $options);

                Notification::make()
                    ->success()
                    ->title('Added '.$added.' '.Str::plural('option', $added).($skipped > 0 ? " ({$skipped} already existed)." : '.'))
                    ->send();
            });
    }

    /** @return array<int, string> */
    protected static function pastedLines(string $text): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/', $text) ?: []),
            fn (string $line): bool => $line !== '',
        ));
    }

    protected static function normalizedName(mixed $label): string
    {
        return mb_strtolower(trim((string) $label));
    }

    /**
     * Refuses to remove, or to change the stored value of, an option that records still store. Editing only a label
     * is fine: stored values don't change.
     */
    public static function optionsInUseRule(): Closure
    {
        return fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            if ($record === null || ! $record instanceof CustomField && ! $record instanceof CompanyCustomField) {
                return;
            }

            $keptValues = collect($value)->pluck('value')->map(fn (mixed $optionValue): string => (string) $optionValue)->all();
            $removedValues = array_values(array_diff(array_keys($record->optionLabels()), $keptValues));

            $message = OptionUsage::describeRemovedOptions($record, array_map('strval', $removedValues));

            if ($message !== null) {
                $fail($message);
            }
        };
    }
}
