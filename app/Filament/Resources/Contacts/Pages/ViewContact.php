<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Actions\QuickTaskAction;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Widgets\ContactActivityFeed;
use App\Filament\Resources\Contacts\Widgets\ContactDetailsWidget;
use App\Models\Activity;
use App\Models\CustomField;
use App\Models\Deal;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ViewContact extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ContactResource::class;

    protected string $view = 'filament.resources.contacts.pages.view-contact';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 3;
    }

    protected function getFooterWidgets(): array
    {
        return [
            ContactDetailsWidget::class,
            ContactActivityFeed::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            QuickTaskAction::makeForContact($this->getRecord()),

            Action::make('logActivity')
                ->label('Log Activity')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('primary')
                ->modalHeading('Log Activity')
                ->schema([
                    Grid::make(2)->schema([
                        Select::make('type')
                            ->options(ActivityType::class)
                            ->required()
                            ->live(),

                        DateTimePicker::make('occurred_at')
                            ->label('Date & Time')
                            ->required()
                            ->default(now())
                            ->native(false),
                    ]),

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

                    Select::make('deal_id')
                        ->label('Deal')
                        ->options(fn (): array => Deal::query()
                            ->where('organization_id', Filament::getTenant()?->id)
                            ->where('contact_id', $this->getRecord()->getKey())
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->createOptionForm([
                            TextInput::make('title')
                                ->required()
                                ->maxLength(255),
                            Select::make('stage')
                                ->options(DealStage::class)
                                ->default(DealStage::Lead)
                                ->required(),
                            TextInput::make('value')
                                ->numeric()
                                ->minValue(0)
                                ->nullable(),
                            Select::make('currency')
                                ->options([
                                    'USD' => 'USD',
                                    'EUR' => 'EUR',
                                    'GBP' => 'GBP',
                                ])
                                ->default('USD')
                                ->required(),
                            Textarea::make('deal_notes')
                                ->rows(3)
                                ->nullable(),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            return Deal::create([
                                'organization_id' => Filament::getTenant()?->id,
                                'contact_id' => $this->getRecord()->getKey(),
                                'title' => $data['title'],
                                'stage' => $data['stage'],
                                'value' => $data['value'] ?? null,
                                'currency' => $data['currency'],
                                'notes' => $data['deal_notes'] ?? null,
                                'status' => DealStatus::Open,
                                'created_by' => auth()->id(),
                            ])->getKey();
                        }),
                ])
                ->action(function (array $data): void {
                    $activityData = collect($data)->toArray();

                    $activityData['contact_id'] = $this->getRecord()->getKey();
                    $activityData['user_id'] = auth()->id();

                    Activity::create($activityData);

                    Notification::make()
                        ->title('Activity logged')
                        ->success()
                        ->send();

                    $this->dispatch('activityLogged');
                }),
            Action::make('customFields')
                ->label('Custom Fields')
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->modalHeading('Custom Fields')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->schema(function (): array {
                    $fields = CustomField::query()
                        ->where('organization_id', Filament::getTenant()?->id)
                        ->orderBy('order')
                        ->get();

                    if ($fields->isEmpty()) {
                        return [
                            TextEntry::make('empty')
                                ->hiddenLabel()
                                ->state('No custom fields defined for this organization.'),
                        ];
                    }

                    $values = $this->getRecord()->custom_field_values ?? [];

                    return $fields->map(function (CustomField $field) use ($values): TextEntry {
                        $rawValue = $values[(string) $field->id] ?? null;

                        $displayValue = match ($field->type) {
                            'select' => collect($field->options ?? [])
                                ->firstWhere('value', $rawValue)['label'] ?? $rawValue ?? '—',
                            'multiselect' => collect($field->options ?? [])
                                ->whereIn('value', (array) $rawValue)
                                ->pluck('label')
                                ->join(', ') ?: '—',
                            default => $rawValue ?? '—',
                        };

                        return TextEntry::make("custom_field_{$field->id}")
                            ->label($field->name)
                            ->state($displayValue);
                    })->all();
                }),

            EditAction::make()
                ->record($this->getRecord()),
        ];
    }
}
