<?php

namespace App\Filament\Resources\Segments\Schemas;

use App\Data\Segments\SegmentConditionData;
use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\ContactAttribute;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\SegmentConditionType;
use App\Enums\SegmentFieldKind;
use App\Enums\SegmentOperator;
use App\Enums\SegmentValueInput;
use App\Models\Company;
use App\Models\CompanyType;
use App\Models\CustomField;
use App\Models\Tag;
use App\Services\Segments\SegmentCountCache;
use App\Services\Segments\SegmentFieldCatalog;
use App\Services\Segments\SegmentQueryBuilder;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The "Add Condition" modal: Type → Field → Operator → value input(s), each select resetting the ones after it.
 *
 * The form state is flat (one state key per kind of input) and mapped to/from the condition's `value` array.
 */
class SegmentConditionForm
{
    /** @var array<int, string> */
    private const VALUE_KEYS = [
        'text_value', 'number_value', 'date_value', 'date_value_to', 'option_value', 'values', 'days', 'month', 'day',
        'activity_types', 'outcome', 'deal_statuses', 'deal_stages', 'min_value',
    ];

    /** @return array<int, mixed> */
    public static function components(SegmentFieldCatalog $catalog): array
    {
        $resetFrom = fn (string ...$keys): \Closure => function (Set $set) use ($keys): void {
            foreach ([...$keys, ...self::VALUE_KEYS] as $key) {
                $set($key, null);
            }
        };

        return [
            Grid::make(3)->schema([
                Select::make('type')
                    ->label('Condition type')
                    ->options(SegmentConditionType::class)
                    ->required()
                    ->live()
                    ->afterStateUpdated($resetFrom('field', 'operator')),

                Select::make('field')
                    ->label('Field')
                    ->options(fn (Get $get): array => self::fieldOptions($catalog, self::type($get), $get('field')))
                    ->searchable()
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => self::type($get)?->requiresField() ?? false)
                    ->afterStateUpdated($resetFrom('operator')),

                Select::make('operator')
                    ->label('Operator')
                    ->options(fn (Get $get): array => collect(self::kind($catalog, $get)?->operators() ?? [])
                        ->mapWithKeys(fn (SegmentOperator $operator): array => [$operator->value => $operator->getLabel()])
                        ->all())
                    ->helperText(fn (Get $get): ?string => self::operator($get)?->includesBlankValues()
                        ? 'Contacts with no value for this field also match.'
                        : null)
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => self::kind($catalog, $get) !== null)
                    ->afterStateUpdated($resetFrom()),
            ]),

            Grid::make(2)->schema([
                TextInput::make('text_value')
                    ->label('Value')
                    ->required()
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::Single
                        && in_array(self::kind($catalog, $get), [SegmentFieldKind::Text, SegmentFieldKind::Email], true)),

                TextInput::make('number_value')
                    ->label('Value')
                    ->numeric()
                    ->required()
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::Single
                        && self::kind($catalog, $get) === SegmentFieldKind::Number),

                DatePicker::make('date_value')
                    ->label(fn (Get $get): string => self::input($get) === SegmentValueInput::Range ? 'From' : 'Date')
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => in_array(self::input($get), [SegmentValueInput::Single, SegmentValueInput::Range], true)
                        && self::kind($catalog, $get) === SegmentFieldKind::Date),

                DatePicker::make('date_value_to')
                    ->label('To')
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::Range),

                Select::make('option_value')
                    ->label('Value')
                    ->options(fn (Get $get): array => $catalog->fieldOptions(self::type($get), $get('field')))
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::Single
                        && self::kind($catalog, $get) === SegmentFieldKind::Select),

                Select::make('values')
                    ->label('Values')
                    ->multiple()
                    ->options(fn (Get $get): array => self::multipleOptions($catalog, $get))
                    ->searchable()
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::Multiple)
                    ->columnSpanFull(),

                Select::make('month')
                    ->label('Month')
                    ->options(collect(range(1, 12))->mapWithKeys(fn (int $month): array => [$month => now()->startOfYear()->month($month)->format('F')])->all())
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => in_array(self::input($get), [SegmentValueInput::Month, SegmentValueInput::DayAndMonth], true)),

                Select::make('day')
                    ->label('Day')
                    ->options(array_combine(range(1, 31), range(1, 31)))
                    ->required()
                    ->live()
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::DayAndMonth),

                Select::make('activity_types')
                    ->label('Activity types')
                    ->placeholder('Any type')
                    ->multiple()
                    ->options(ActivityType::class)
                    ->live()
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::ActivityCriteria),

                Select::make('outcome')
                    ->label('Outcome')
                    ->placeholder('Any outcome')
                    ->options(ActivityOutcome::class)
                    ->live()
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::ActivityCriteria
                        && self::operator($get) !== SegmentOperator::LastActivityMoreThanDaysAgo),

                Select::make('deal_statuses')
                    ->label('Deal status')
                    ->placeholder('Any status')
                    ->multiple()
                    ->options(DealStatus::class)
                    ->live()
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::DealCriteria),

                Select::make('deal_stages')
                    ->label('Deal stage')
                    ->placeholder('Any stage')
                    ->multiple()
                    ->options(DealStage::class)
                    ->live()
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::DealCriteria),

                TextInput::make('min_value')
                    ->label('Minimum deal value')
                    ->numeric()
                    ->minValue(0)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => self::input($get) === SegmentValueInput::DealCriteria),

                TextInput::make('days')
                    ->label(fn (Get $get): string => self::input($get) === SegmentValueInput::ActivityCriteria && ! self::operator($get)?->requiresDays()
                        ? 'In the last (days)'
                        : 'Number of days')
                    ->placeholder(fn (Get $get): ?string => self::operator($get)?->requiresDays() ? null : 'Any time')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(SegmentFieldCatalog::MAX_DAYS)
                    ->validationMessages(['max' => 'Enter at most '.number_format(SegmentFieldCatalog::MAX_DAYS).' days (about 100 years).'])
                    ->suffix('days')
                    ->required(fn (Get $get): bool => self::operator($get)?->requiresDays() ?? false)
                    ->live(onBlur: true)
                    ->visible(fn (Get $get): bool => in_array(self::input($get), [SegmentValueInput::Days, SegmentValueInput::ActivityCriteria], true)),
            ]),

            TextEntry::make('preview')
                ->hiddenLabel()
                ->color('gray')
                ->state(function (Get $get) use ($catalog): string {
                    $condition = self::toCondition(self::state($get), $catalog);

                    if (! $condition || ! $catalog->isConditionComplete($condition)) {
                        return 'Complete the condition to preview how many contacts it matches.';
                    }

                    $count = (new SegmentCountCache(new SegmentQueryBuilder($catalog)))->countConditions([$condition]);

                    return "This condition matches {$count} ".Str::plural('contact', $count).'.';
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function toCondition(array $data, SegmentFieldCatalog $catalog, ?string $id = null): ?SegmentConditionData
    {
        $type = self::enum(SegmentConditionType::class, $data['type'] ?? null);
        $operator = self::enum(SegmentOperator::class, $data['operator'] ?? null);

        if (! $type || ! $operator) {
            return null;
        }

        $field = $type->requiresField() ? ($data['field'] ?? null) : null;
        $kind = $catalog->fieldKind(new SegmentConditionData('preview', $type, $field, $operator));

        $value = match ($operator->valueInput()) {
            SegmentValueInput::None => [],
            SegmentValueInput::Single => ['value' => match ($kind) {
                SegmentFieldKind::Text, SegmentFieldKind::Email => $data['text_value'] ?? null,
                SegmentFieldKind::Date => $data['date_value'] ?? null,
                SegmentFieldKind::Select => $data['option_value'] ?? null,
                SegmentFieldKind::Number => $data['number_value'] ?? null,
                default => null,
            }],
            SegmentValueInput::Range => ['value' => $data['date_value'] ?? null, 'value_to' => $data['date_value_to'] ?? null],
            SegmentValueInput::Multiple => ['values' => in_array($type, [SegmentConditionType::Tags, SegmentConditionType::Company], true)
                ? array_values(array_map('intval', (array) ($data['values'] ?? [])))
                : array_values((array) ($data['values'] ?? []))],
            SegmentValueInput::Days => ['days' => $data['days'] ?? null],
            SegmentValueInput::Month => ['month' => $data['month'] ?? null],
            SegmentValueInput::DayAndMonth => ['month' => $data['month'] ?? null, 'day' => $data['day'] ?? null],
            SegmentValueInput::ActivityCriteria => [
                'activity_types' => self::enumValues($data['activity_types'] ?? []),
                'outcome' => self::enumValue($data['outcome'] ?? null),
                'days' => $data['days'] ?? null,
            ],
            SegmentValueInput::DealCriteria => [
                'deal_statuses' => self::enumValues($data['deal_statuses'] ?? []),
                'deal_stages' => self::enumValues($data['deal_stages'] ?? []),
                'min_value' => $data['min_value'] ?? null,
            ],
        };

        return new SegmentConditionData(
            $id ?? (string) Str::ulid(),
            $type,
            $field,
            $operator,
            array_filter($value, fn (mixed $item): bool => filled($item)),
        );
    }

    /**
     * The form state used to edit an existing condition.
     *
     * @return array<string, mixed>
     */
    public static function fill(SegmentConditionData $condition, SegmentFieldCatalog $catalog): array
    {
        $kind = $catalog->fieldKind($condition);
        $singleKey = match ($kind) {
            SegmentFieldKind::Number => 'number_value',
            SegmentFieldKind::Date => 'date_value',
            SegmentFieldKind::Select => 'option_value',
            default => 'text_value',
        };

        return [
            'type' => $condition->type->value,
            'field' => $condition->field,
            'operator' => $condition->operator->value,
            $singleKey => $condition->value('value'),
            'date_value_to' => $condition->value('value_to'),
            'values' => $condition->values(),
            'days' => $condition->value('days'),
            'month' => $condition->value('month'),
            'day' => $condition->value('day'),
            'activity_types' => $condition->values('activity_types'),
            'outcome' => $condition->value('outcome'),
            'deal_statuses' => $condition->values('deal_statuses'),
            'deal_stages' => $condition->values('deal_stages'),
            'min_value' => $condition->value('min_value'),
        ];
    }

    /** @return array<string, string> */
    private static function fieldOptions(SegmentFieldCatalog $catalog, ?SegmentConditionType $type, ?string $currentField = null): array
    {
        return match ($type) {
            SegmentConditionType::Attribute => collect(ContactAttribute::cases())
                ->mapWithKeys(fn (ContactAttribute $attribute): array => [$attribute->value => $attribute->getLabel()])
                ->all(),
            SegmentConditionType::CustomField => $catalog->customFields()
                ->reject(fn (CustomField $field): bool => $field->trashed() && $field->key !== $currentField)
                ->mapWithKeys(fn (CustomField $field): array => [$field->key => $field->name.($field->trashed() ? ' (deleted)' : '')])
                ->all(),
            default => [],
        };
    }

    /** @return array<int|string, string> */
    private static function multipleOptions(SegmentFieldCatalog $catalog, Get $get): array
    {
        $type = self::type($get);
        $selectedIds = array_filter((array) $get('values'), fn (mixed $id): bool => is_numeric($id));

        $names = fn (string $model): array => $model::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $catalog->organizationId)
            ->where(fn (Builder $query): Builder => $query->whereNull('deleted_at')->orWhereIn('id', $selectedIds))
            ->orderBy('name')
            ->get(['id', 'name', 'deleted_at'])
            ->mapWithKeys(fn (Tag|Company|CompanyType $record): array => [$record->id => $record->name.($record->trashed() ? ' (deleted)' : '')])
            ->all();

        return match (true) {
            $type === SegmentConditionType::Tags => $names(Tag::class),
            self::operator($get) === SegmentOperator::CompanyTypeIsAnyOf => $names(CompanyType::class),
            $type === SegmentConditionType::Company => $names(Company::class),
            default => $catalog->fieldOptions($type ?? SegmentConditionType::Attribute, $get('field')),
        };
    }

    /** @return array<string, mixed> */
    private static function state(Get $get): array
    {
        return collect(['type', 'field', 'operator', ...self::VALUE_KEYS])
            ->mapWithKeys(fn (string $key): array => [$key => $get($key)])
            ->all();
    }

    private static function type(Get $get): ?SegmentConditionType
    {
        return self::enum(SegmentConditionType::class, $get('type'));
    }

    private static function operator(Get $get): ?SegmentOperator
    {
        return self::enum(SegmentOperator::class, $get('operator'));
    }

    private static function input(Get $get): ?SegmentValueInput
    {
        return self::operator($get)?->valueInput();
    }

    private static function kind(SegmentFieldCatalog $catalog, Get $get): ?SegmentFieldKind
    {
        $type = self::type($get);

        if (! $type) {
            return null;
        }

        if ($type->requiresField() && blank($get('field'))) {
            return null;
        }

        return $catalog->fieldKind(new SegmentConditionData('preview', $type, $get('field'), SegmentOperator::IsBlank));
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function enum(string $enum, mixed $value): ?BackedEnum
    {
        if ($value instanceof $enum) {
            return $value;
        }

        return is_string($value) ? $enum::tryFrom($value) : null;
    }

    private static function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    /** @return array<int, mixed> */
    private static function enumValues(mixed $values): array
    {
        return array_values(array_map(fn (mixed $value): mixed => self::enumValue($value), (array) $values));
    }
}
