<?php

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\DealStatus;
use App\Filament\Support\RecordKeyField;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\LostReason;
use App\Services\Deals\DealCompany;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use App\Services\Owners\OwnerOptions;
use App\Support\MoneyLimit;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class DealForm
{
    /**
     * The attributes a deal is created with, from the create page and from the board: the current organization, the creator, and the status and won / lost dates the stage decides.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateDataBeforeCreate(array $data): array
    {
        $data['organization_id'] = CurrentOrganization::get()->id;
        $data['created_by'] = Auth::id();

        return [...$data, ...DealStageMover::attributesFor(DefaultPipeline::stageById($data['organization_id'], $data['pipeline_stage_id']))];
    }

    /**
     * The stage field of every deal form: the stages of the current organization's pipeline in order, "Lead" (the first open stage) by default.
     */
    public static function stageSelect(): Select
    {
        return RecordKeyField::select('pipeline_stage_id')
            ->label('Stage')
            ->options(fn (): array => DefaultPipeline::stageOptions(CurrentOrganization::get()->id))
            ->in(fn (): array => array_keys(DefaultPipeline::stageOptions(CurrentOrganization::get()->id)))
            ->default(fn (): int => DefaultPipeline::stageOfType(CurrentOrganization::get()->id, DealStatus::Open)->getKey())
            ->required();
    }

    /**
     * The optional lost reason and note: used by the deal form (only while the Lost stage is picked) and by "Move to Lost".
     * The note is trimmed and an empty one is stored as null.
     *
     * @return array<int, Component>
     */
    public static function lostReasonFields(): array
    {
        return [
            RecordKeyField::select('lost_reason_id')
                ->label('Lost reason')
                ->options(fn (?Deal $record): array => static::lostReasonOptions($record))
                ->in(fn (?Deal $record): array => array_keys(static::lostReasonOptions($record)))
                ->placeholder('No reason')
                ->searchable()
                ->nullable(),

            Textarea::make('lost_reason_note')
                ->label('Lost note')
                ->rows(3)
                ->maxLength(1000)
                ->nullable()
                ->dehydrateStateUsing(fn (?string $state): ?string => filled(trim((string) $state)) ? trim((string) $state) : null),
        ];
    }

    /**
     * The lost reasons a deal can be given, by name: the current organization's reasons that are not deleted, plus the
     * reason the deal already has when it was deleted since (so editing a lost deal keeps it).
     *
     * @return array<int, string>
     */
    public static function lostReasonOptions(?Deal $record): array
    {
        $options = LostReason::query()
            ->forOrganization(CurrentOrganization::get()->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        if ($record?->lost_reason_id !== null && ! array_key_exists($record->lost_reason_id, $options) && $record->lostReasonLabel() !== null) {
            $options[$record->lost_reason_id] = $record->lostReasonLabel();
        }

        return $options;
    }

    /**
     * Whether the stage id (a value from the form, so any type) is the Lost stage of the current organization.
     */
    public static function isLostStage(mixed $stageId): bool
    {
        return filter_var($stageId, FILTER_VALIDATE_INT) !== false
            && (int) $stageId === DefaultPipeline::stageOfType(CurrentOrganization::get()->id, DealStatus::Lost)->getKey();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),

                RecordKeyField::select('contact_id')
                    ->label('Contact')
                    ->relationship(
                        'contact',
                        'name',
                        fn ($query) => $query->where('organization_id', CurrentOrganization::find()?->id)
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set, mixed $state): void {
                        if (blank($get('company_id'))) {
                            $set('company_id', DealCompany::defaultIdFor($state));
                        }
                    })
                    ->rules([
                        fn () => Rule::exists(Contact::class, 'id')->where(
                            fn ($query) => $query->where('organization_id', CurrentOrganization::find()?->id)
                        ),
                    ]),

                RecordKeyField::select('company_id')
                    ->label('Company')
                    ->relationship(
                        'company',
                        'name',
                        fn (Builder $query): Builder => $query->where('companies.organization_id', CurrentOrganization::find()?->id)
                    )
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->placeholder('No company')
                    ->getOptionLabelUsing(fn (mixed $value, ?Deal $record): ?string => static::companyLabel($value, $record))
                    ->rules([
                        'bail',
                        'integer',
                        fn (?Deal $record) => Rule::exists(Company::class, 'id')->where(
                            fn ($query) => $query
                                ->where('organization_id', CurrentOrganization::find()?->id)
                                ->where(fn ($query) => $query->whereNull('deleted_at')->orWhere('id', $record?->company_id ?? 0))
                        ),
                    ]),

                RecordKeyField::select('owner_id')
                    ->label('Deal owner')
                    ->options(fn (?Deal $record): array => OwnerOptions::forForm($record))
                    ->in(fn (?Deal $record): array => array_keys(OwnerOptions::forForm($record)))
                    ->default(fn (): ?int => Auth::id())
                    ->searchable()
                    ->nullable()
                    ->placeholder('No owner'),

                static::stageSelect()->live(),

                ...array_map(
                    fn (Component $field): Component => $field->visible(fn (Get $get): bool => static::isLostStage($get('pipeline_stage_id'))),
                    static::lostReasonFields(),
                ),

                TextInput::make('value')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(MoneyLimit::MAX)
                    ->nullable(),

                DatePicker::make('expected_close_date')
                    ->label('Expected Close Date')
                    ->nullable(),

                Textarea::make('notes')
                    ->nullable()
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The label of the company currently on the deal (shown as deleted when it is), or of a live company for any other value.
     */
    protected static function companyLabel(mixed $value, ?Deal $record): ?string
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        $company = Company::query()
            ->when($record !== null && (int) $value === $record->company_id, fn (Builder $query): Builder => $query->withTrashed())
            ->find($value);

        if ($company === null) {
            return null;
        }

        return $company->name.($company->trashed() ? ' (deleted)' : '');
    }
}
