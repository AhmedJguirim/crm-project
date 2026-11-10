<?php

namespace App\Filament\Resources\Contacts\Tables;

use App\Enums\ContactStatus;
use App\Enums\LeadSource;
use App\Filament\Actions\AssignOwnerBulkAction;
use App\Filament\Actions\ExportContactsAction;
use App\Filament\Actions\LogActivityAction;
use App\Filament\Actions\QuickTaskAction;
use App\Filament\Support\Filters\OptionSelectFilter;
use App\Filament\Support\Filters\RecordKeySelectFilter;
use App\Filament\Support\NullsLastSort;
use App\Jobs\ResyncContactSegments;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\Owners\OwnerOptions;
use App\Support\NotificationText;
use App\Support\Tenancy\CurrentOrganization;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['owner.organizations', 'tags']))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(query: NullsLastSort::column('email')),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(query: NullsLastSort::column('status')),

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

                TextColumn::make('owner_id')
                    ->label('Contact owner')
                    ->state(fn (Contact $record): ?string => $record->ownerLabel())
                    ->placeholder('No owner')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                OptionSelectFilter::make('status')
                    ->options(ContactStatus::class)
                    ->multiple(),

                OptionSelectFilter::make('lead_source')
                    ->label('Lead Source')
                    ->options(LeadSource::class)
                    ->multiple(),

                RecordKeySelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->multiple()
                    ->preload(),

                RecordKeySelectFilter::make('segments')
                    ->relationship('segments', 'name', fn (Builder $query): Builder => $query->where('is_published', true))
                    ->multiple()
                    ->preload(),

                RecordKeySelectFilter::make('owner_id')
                    ->label('Contact owner')
                    ->options(fn (): array => OwnerOptions::forFilter(Contact::class))
                    ->searchable(),

                TrashedFilter::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordAction(ViewAction::class)
            ->recordActions([
                RestoreAction::make(),
                DeleteAction::make(),
                QuickTaskAction::makeForContactsTable(),

                LogActivityAction::forContactRow(),
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
                                ->title(NotificationText::plain("Updated {$records->count()} contacts"))
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
                                    ->where('organization_id', CurrentOrganization::find()?->id)
                                    ->pluck('name', 'id')
                                    ->all())
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn (Contact $contact) => $contact->tags()->syncWithoutDetaching($data['tags']));

                            ResyncContactSegments::dispatchForContacts(CurrentOrganization::find()?->id, $records->modelKeys());

                            Notification::make()
                                ->title(NotificationText::plain("Tagged {$records->count()} contacts"))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    AssignOwnerBulkAction::make('contact', 'Contact owner'),

                    ExportContactsAction::forSelection(),

                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                    RestoreBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->emptyStateHeading('No contacts yet')
            ->emptyStateDescription('Add your first contact or import from CSV.');
    }
}
