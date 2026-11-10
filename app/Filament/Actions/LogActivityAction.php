<?php

namespace App\Filament\Actions;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Support\AbilityCheck;
use App\Filament\Support\RecordKeyField;
use App\Models\Activity;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Services\Deals\DealCompany;
use App\Services\Deals\DealStageMover;
use App\Services\Deals\DefaultPipeline;
use App\Support\MoneyLimit;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

final class LogActivityAction
{
    /**
     * Logs an activity on the action's record (a deal), linked to the deal's contact when it has a live one, otherwise to the deal only.
     */
    public static function forDeal(string $name = 'logActivity'): Action
    {
        return self::base($name)
            ->visible(fn (?Deal $record): bool => $record !== null)
            ->modalHeading(fn (?Deal $record): string => $record === null ? 'Log Activity' : "Log Activity — {$record->title}")
            ->modalDescription(fn (?Deal $record): ?string => $record === null
                ? null
                : ($record->contact === null ? 'No contact' : "Contact: {$record->contact->name}"))
            ->fillForm(fn (Deal $record): array => [
                'occurred_at' => now(),
                'type' => self::rememberedType(),
                'deal_id' => $record->getKey(),
            ])
            ->schema(function (?Deal $record): array {
                if ($record === null) {
                    return [];
                }

                $contactId = $record->contact?->getKey();

                if ($contactId === null) {
                    return self::fields(
                        self::dealSelect(fn (): array => Deal::query()
                            ->where('organization_id', $record->organization_id)
                            ->whereKey($record->getKey())
                            ->pluck('title', 'id')
                            ->all())
                            ->required(),
                        false,
                    );
                }

                return self::fields(
                    self::inlineDealCreate(
                        self::dealSelect(self::contactDeals($record->organization_id, $contactId))->required(),
                        $record->organization_id,
                        $contactId,
                    ),
                    false,
                );
            })
            ->action(fn (array $data, Deal $record, Component $livewire) => self::store(
                $data,
                $record->contact?->getKey(),
                $data['deal_id'],
                null,
                $livewire,
            ));
    }

    /**
     * Logs an activity on the action's record (a company), optionally also linked to one of the company's deals.
     */
    public static function forCompany(string $name = 'logActivity'): Action
    {
        return self::base($name)
            ->visible(fn (?Company $record): bool => $record !== null && ! $record->trashed())
            ->modalHeading(fn (?Company $record): string => $record === null ? 'Log Activity' : "Log Activity — {$record->name}")
            ->fillForm(fn (): array => ['occurred_at' => now(), 'type' => self::rememberedType()])
            ->schema(fn (?Company $record): array => $record === null ? [] : self::fields(
                self::dealSelect(fn (): array => Deal::query()
                    ->where('organization_id', $record->organization_id)
                    ->where('company_id', $record->getKey())
                    ->orderBy('title')
                    ->pluck('title', 'id')
                    ->all())
                    ->nullable(),
                false,
            ))
            ->action(fn (array $data, Company $record, Component $livewire) => self::store(
                $data,
                null,
                $data['deal_id'] ?? null,
                $record->getKey(),
                $livewire,
            ));
    }

    /**
     * Logs an activity on the action's record (a contact), optionally also linked to one of the contact's deals (or a deal created on the spot).
     */
    public static function forContact(string $name = 'logActivity'): Action
    {
        return self::forContactRecord($name, false);
    }

    /**
     * Logs an activity from a contacts table row with the compact form: type, date, notes and deal.
     */
    public static function forContactRow(string $name = 'logActivity'): Action
    {
        return self::forContactRecord($name, true)
            ->label('Log')
            ->color('gray');
    }

    private static function forContactRecord(string $name, bool $compact): Action
    {
        return self::base($name)
            ->modalHeading(fn (?Contact $record): string => $record === null ? 'Log Activity' : "Log Activity — {$record->name}")
            ->fillForm(fn (): array => ['occurred_at' => now(), 'type' => self::rememberedType()])
            ->schema(fn (?Contact $record): array => $record === null ? [] : self::fields(
                self::inlineDealCreate(
                    self::dealSelect(self::contactDeals($record->organization_id, $record->getKey()))->nullable(),
                    $record->organization_id,
                    $record->getKey(),
                ),
                $compact,
            ))
            ->action(fn (array $data, Contact $record, Component $livewire) => self::store(
                $data,
                $record->getKey(),
                $data['deal_id'] ?? null,
                null,
                $livewire,
            ));
    }

    /**
     * The type of the current user's most recently logged activity (by creation, not by occurrence), or null when there is none or the stored value is not an ActivityType.
     * It is remembered per user across all of the user's organizations (PO, 2026-11-10), so the organization scope is dropped on purpose.
     */
    private static function rememberedType(): ?ActivityType
    {
        $userId = auth()->id();

        if ($userId === null) {
            return null;
        }

        $type = Activity::query()
            ->withoutGlobalScope('organization')
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->toBase()
            ->value('type');

        return is_string($type) ? ActivityType::tryFrom($type) : null;
    }

    private static function base(string $name): Action
    {
        return Action::make($name)
            ->authorize(AbilityCheck::for('create', Activity::class))
            ->label('Log Activity')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary');
    }

    /**
     * @return array<int, Grid|TextInput|Textarea|Select>
     */
    private static function fields(Select $dealSelect, bool $compact): array
    {
        $typeAndDate = Grid::make(2)->schema([
            Select::make('type')
                ->options(ActivityType::class)
                ->required()
                ->live(),

            DateTimePicker::make('occurred_at')
                ->label('Date & Time')
                ->required()
                ->native(false),
        ]);

        if ($compact) {
            return [
                $typeAndDate,
                Textarea::make('notes')->rows(3),
                $dealSelect,
            ];
        }

        return [
            $typeAndDate,

            TextInput::make('duration_minutes')
                ->label('Duration (minutes)')
                ->numeric()
                ->minValue(1)
                ->maxValue(1440)
                ->suffix('min')
                ->visible(function (Get $get): bool {
                    $type = $get('type');

                    if ($type instanceof ActivityType) {
                        return $type->hasDuration();
                    }

                    return ActivityType::tryFrom($type ?? '')?->hasDuration() ?? false;
                }),

            TextInput::make('subject')
                ->maxLength(255),

            Textarea::make('notes')
                ->rows(3),

            Select::make('outcome')
                ->options(ActivityOutcome::class),

            $dealSelect,
        ];
    }

    /**
     * @param  Closure(): array<int|string, string>  $options
     */
    private static function dealSelect(Closure $options): Select
    {
        return RecordKeyField::select('deal_id')
            ->label('Deal')
            ->options($options)
            ->searchable()
            ->preload();
    }

    /**
     * @return Closure(): array<int|string, string>
     */
    private static function contactDeals(int $organizationId, int $contactId): Closure
    {
        return fn (): array => Deal::query()
            ->where('organization_id', $organizationId)
            ->where('contact_id', $contactId)
            ->orderBy('title')
            ->pluck('title', 'id')
            ->all();
    }

    private static function inlineDealCreate(Select $select, int $organizationId, int $contactId): Select
    {
        return $select
            ->createOptionForm([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
                DealForm::stageSelect(),
                TextInput::make('value')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(MoneyLimit::MAX)
                    ->nullable(),
                Textarea::make('deal_notes')
                    ->rows(3)
                    ->nullable(),
            ])
            ->createOptionUsing(fn (array $data): int => Deal::create([
                'organization_id' => $organizationId,
                'contact_id' => $contactId,
                'company_id' => DealCompany::defaultIdFor($contactId),
                'title' => $data['title'],
                ...DealStageMover::attributesFor(DefaultPipeline::stageById($organizationId, $data['pipeline_stage_id'])),
                'value' => $data['value'] ?? null,
                'notes' => $data['deal_notes'] ?? null,
                'created_by' => auth()->id(),
                'owner_id' => auth()->id(),
            ])->getKey());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function store(array $data, ?int $contactId, ?int $dealId, ?int $companyId, Component $livewire): void
    {
        Activity::create([
            'contact_id' => $contactId,
            'deal_id' => $dealId,
            'company_id' => $companyId,
            'user_id' => auth()->id(),
            'type' => $data['type'],
            'occurred_at' => $data['occurred_at'],
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'subject' => $data['subject'] ?? null,
            'notes' => $data['notes'] ?? null,
            'outcome' => $data['outcome'] ?? null,
        ]);

        Notification::make()
            ->title('Activity logged')
            ->success()
            ->send();

        $livewire->dispatch('activityLogged');
    }
}
