<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\ActivityOutcome;
use App\Enums\ActivityType;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Actions\QuickTaskAction;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Deals\DealResource;
use App\Filament\Resources\Deals\Widgets\DealActivityFeed;
use App\Filament\Resources\Deals\Widgets\DealDetailsWidget;
use App\Filament\Resources\Deals\Widgets\DealInvoicesWidget;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\AbilityCheck;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\Invoice;
use App\Support\MoneyLimit;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ViewDeal extends Page
{
    use InteractsWithRecord;

    protected static string $resource = DealResource::class;

    protected string $view = 'filament.resources.deals.pages.view-deal';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return (string) $this->getRecord()->title;
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 3;
    }

    protected function getFooterWidgets(): array
    {
        return [
            DealDetailsWidget::class,
            DealInvoicesWidget::class,
            DealActivityFeed::class,
        ];
    }

    public function getBreadcrumbs(): array
    {
        $record = $this->getRecord();
        $breadcrumbs = [
            DealResource::getUrl('index') => 'Pipeline',
        ];

        if ($record->contact) {
            $breadcrumbs[ContactResource::getUrl('view', ['record' => $record->contact])] = $record->contact->name;
        } else {
            $breadcrumbs[] = 'No Contact';
        }

        $breadcrumbs[] = $record->title;

        return $breadcrumbs;
    }

    protected function getHeaderActions(): array
    {
        $isOpen = fn (): bool => $this->getRecord()->status === DealStatus::Open;

        return [
            ...($this->getRecord()->contact
                ? [QuickTaskAction::makeForContact($this->getRecord()->contact)]
                : []),

            Action::make('logActivity')
                ->authorize(AbilityCheck::for('create', Activity::class))
                ->label('Log Activity')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->color('primary')
                ->visible(fn (): bool => filled($this->getRecord()->contact_id))
                ->modalHeading('Log Activity')
                ->fillForm(fn (): array => [
                    'deal' => $this->getRecord()->title,
                    'contact_name' => $this->getRecord()->contact?->name,
                    'deal_id' => $this->getRecord()->getKey(),
                    'contact_id' => $this->getRecord()->contact_id,
                    'occurred_at' => now(),
                ])
                ->schema([
                    TextInput::make('deal')
                        ->label('Deal')
                        ->disabled()
                        ->dehydrated(false),

                    TextInput::make('contact_name')
                        ->label('Contact')
                        ->disabled()
                        ->dehydrated(false),

                    Grid::make(2)->schema([
                        Select::make('type')
                            ->options(ActivityType::class)
                            ->required()
                            ->live(),

                        DateTimePicker::make('occurred_at')
                            ->label('Date & Time')
                            ->required()
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
                            ->where('organization_id', $this->getRecord()->organization_id)
                            ->where('contact_id', $this->getRecord()->contact_id)
                            ->orderBy('title')
                            ->pluck('title', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required()
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
                                ->maxValue(MoneyLimit::MAX)
                                ->nullable(),
                            Textarea::make('deal_notes')
                                ->rows(3)
                                ->nullable(),
                        ])
                        ->createOptionUsing(function (array $data): int {
                            return Deal::create([
                                'organization_id' => $this->getRecord()->organization_id,
                                'contact_id' => $this->getRecord()->contact_id,
                                'title' => $data['title'],
                                'stage' => $data['stage'],
                                'value' => $data['value'] ?? null,
                                'notes' => $data['deal_notes'] ?? null,
                                'status' => DealStatus::Open,
                                'created_by' => auth()->id(),
                            ])->getKey();
                        }),

                ])
                ->action(function (array $data): void {
                    Activity::create([
                        'contact_id' => $this->getRecord()->contact_id,
                        'user_id' => auth()->id(),
                        'type' => $data['type'],
                        'occurred_at' => $data['occurred_at'],
                        'duration_minutes' => $data['duration_minutes'] ?? null,
                        'subject' => $data['subject'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'outcome' => $data['outcome'] ?? null,
                        'deal_id' => $data['deal_id'],
                    ]);

                    Notification::make()
                        ->title('Activity logged')
                        ->success()
                        ->send();

                    $this->dispatch('activityLogged');
                }),

            Action::make('createInvoice')
                ->authorize(AbilityCheck::for('create', Invoice::class))
                ->label('Create Invoice')
                ->icon(Heroicon::OutlinedDocumentCurrencyDollar)
                ->color('gray')
                ->url(fn (): string => InvoiceResource::getUrl('create', [
                    'deal' => $this->getRecord()->getKey(),
                ])),

            Action::make('moveToWon')
                ->authorize('update')
                ->label('Move to Won')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible($isOpen)
                ->requiresConfirmation()
                ->modalHeading('Move deal to Won?')
                ->modalDescription('This will close the deal as Won and set the won date to now.')
                ->modalSubmitActionLabel('Yes, mark as won')
                ->action(function (): void {
                    $this->getRecord()->update([
                        'status' => DealStatus::Won,
                        'stage' => DealStage::Won,
                        'won_at' => now(),
                        'lost_at' => null,
                    ]);

                    Notification::make()
                        ->title('Deal moved to Won')
                        ->success()
                        ->send();

                    $this->dispatch('activityLogged');
                }),

            Action::make('moveToLost')
                ->authorize('update')
                ->label('Move to Lost')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible($isOpen)
                ->requiresConfirmation()
                ->modalHeading('Move deal to Lost?')
                ->modalDescription('This will close the deal as Lost and set the lost date to now.')
                ->modalSubmitActionLabel('Yes, mark as lost')
                ->action(function (): void {
                    $this->getRecord()->update([
                        'status' => DealStatus::Lost,
                        'stage' => DealStage::Lost,
                        'lost_at' => now(),
                        'won_at' => null,
                    ]);

                    Notification::make()
                        ->title('Deal moved to Lost')
                        ->success()
                        ->send();
                }),

            EditAction::make()
                ->record(fn (): Model => $this->getRecord()),
        ];
    }
}
