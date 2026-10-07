<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Enums\ActivityType;
use App\Enums\ContactStatus;
use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Enums\LeadSource;
use App\Filament\Actions\ExportContactsAction;
use App\Filament\Actions\QuickTaskAction;
use App\Filament\Support\AbilityCheck;
use App\Jobs\ResyncContactSegments;
use App\Models\Activity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Tag;
use App\Support\MoneyLimit;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('phone')
                    ->toggleable(),

                TextColumn::make('lead_source')
                    ->label('Lead Source')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('companies.name')
                    ->label('Companies')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('tags')
                    ->badge()
                    ->color(fn ($record, $state): array => Color::hex($state['color'] ?? '#94a3b8'))
                    ->formatStateUsing(fn ($state): string => $state['name'])
                    ->separator(',')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ContactStatus::class)
                    ->multiple(),

                SelectFilter::make('lead_source')
                    ->label('Lead Source')
                    ->options(LeadSource::class)
                    ->multiple(),

                SelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->preload(),

                SelectFilter::make('segments')
                    ->relationship('segments', 'name', fn (Builder $query): Builder => $query->where('is_published', true))
                    ->multiple()
                    ->preload(),

                TrashedFilter::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordAction(ViewAction::class)
            ->recordActions([
                RestoreAction::make(),
                DeleteAction::make(),
                QuickTaskAction::makeForContactsTable(),

                Action::make('logActivity')
                    ->authorize(AbilityCheck::for('create', Activity::class))
                    ->label('Log')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('gray')
                    ->modalHeading('Quick Log Activity')
                    ->schema(fn ($record): array => [
                        Select::make('type')
                            ->options(ActivityType::class)
                            ->required(),

                        DateTimePicker::make('occurred_at')
                            ->label('Date & Time')
                            ->required()
                            ->default(now())
                            ->native(false),

                        Select::make('deal_id')
                            ->label('Deal')
                            ->options(fn (): array => Deal::query()
                                ->where('organization_id', Filament::getTenant()?->id)
                                ->where('contact_id', $record->getKey())
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
                                    ->maxValue(MoneyLimit::MAX)
                                    ->nullable(),
                                Select::make('currency')
                                    ->options([
                                        'USD' => 'USD',
                                        'EUR' => 'EUR',
                                        'GBP' => 'GBP',
                                    ])
                                    ->default('USD')
                                    ->required(),
                                Textarea::make('notes')
                                    ->rows(3)
                                    ->nullable(),
                            ])
                            ->createOptionUsing(function (array $data) use ($record): int {
                                return Deal::create([
                                    'organization_id' => Filament::getTenant()?->id,
                                    'contact_id' => $record->getKey(),
                                    'title' => $data['title'],
                                    'stage' => $data['stage'],
                                    'value' => $data['value'] ?? null,
                                    'currency' => $data['currency'],
                                    'notes' => $data['notes'] ?? null,
                                    'status' => DealStatus::Open,
                                    'created_by' => auth()->id(),
                                ])->getKey();
                            }),

                        Textarea::make('notes')
                            ->rows(3),
                    ])
                    ->action(function (array $data, $record): void {
                        Activity::create([
                            ...$data,
                            'contact_id' => $record->getKey(),
                            'user_id' => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('Activity logged')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('changeStatus')
                        ->authorize('updateAny')
                        ->authorizeIndividualRecords('update')
                        ->label('Change Status')
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->schema([
                            Select::make('status')
                                ->options(ContactStatus::class)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn (Contact $contact) => $contact->update([
                                'status' => $data['status'],
                            ]));

                            Notification::make()
                                ->title("Updated {$records->count()} contacts")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('addTags')
                        ->authorize('updateAny')
                        ->authorizeIndividualRecords('update')
                        ->label('Add Tags')
                        ->icon(Heroicon::OutlinedTag)
                        ->schema([
                            Select::make('tags')
                                ->multiple()
                                ->options(fn (): array => Tag::query()
                                    ->where('organization_id', Filament::getTenant()?->id)
                                    ->pluck('name', 'id')
                                    ->all())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn (Contact $contact) => $contact->tags()->syncWithoutDetaching($data['tags']));

                            ResyncContactSegments::dispatchForContacts(Filament::getTenant()?->id, $records->modelKeys());

                            Notification::make()
                                ->title("Tagged {$records->count()} contacts")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    ExportContactsAction::forSelection(),

                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                    RestoreBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->emptyStateHeading('No contacts yet')
            ->emptyStateDescription('Add your first contact or import from CSV.');
    }
}
