<?php

namespace App\Filament\Resources\Deals\Tables;

use App\Enums\DealStage;
use App\Enums\DealStatus;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Support\NullsLastSort;
use App\Models\Deal;
use App\Services\Owners\OwnerOptions;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DealsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['owner.organizations', 'companyWithTrashed']))
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact.name')
                    ->label('Contact')
                    ->placeholder('No contact')
                    ->url(fn (Deal $record): ?string => $record->contact
                        ? ContactResource::getUrl('view', ['record' => $record->contact])
                        : null),

                TextColumn::make('company_id')
                    ->label('Company')
                    ->state(fn (Deal $record): ?string => $record->companyLabel())
                    ->placeholder('No company')
                    ->url(fn (Deal $record): ?string => $record->companyWithTrashed
                        ? CompanyResource::getUrl('edit', ['record' => $record->companyWithTrashed])
                        : null),

                TextColumn::make('owner_id')
                    ->label('Deal owner')
                    ->state(fn (Deal $record): ?string => $record->ownerLabel())
                    ->placeholder('No owner'),

                TextColumn::make('value')
                    ->money(fn (): string => Filament::getTenant()?->currencyCode() ?? 'USD')
                    ->placeholder('—')
                    ->sortable(query: NullsLastSort::column('value')),

                TextColumn::make('stage')
                    ->badge()
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('expected_close_date')
                    ->label('Expected Close')
                    ->date()
                    ->placeholder('—')
                    ->sortable(query: NullsLastSort::column('expected_close_date')),

                TextColumn::make('created_at')
                    ->label('Created At')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('stage')
                    ->options(DealStage::class)
                    ->multiple(),

                SelectFilter::make('status')
                    ->options(DealStatus::class),

                SelectFilter::make('contact_id')
                    ->label('Contact')
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('company_id')
                    ->label('Company')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('owner_id')
                    ->label('Deal owner')
                    ->options(fn (): array => OwnerOptions::forFilter(Deal::class))
                    ->searchable(),

                Filter::make('closing_this_week')
                    ->label('Closing This Week')
                    ->query(fn (Builder $query): Builder => $query->closingThisWeek()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assignOwner')
                        ->authorize('updateAny')
                        ->authorizeIndividualRecords('update')
                        ->label('Assign owner')
                        ->icon(Heroicon::OutlinedUserCircle)
                        ->modalHeading('Assign owner')
                        ->modalSubmitActionLabel('Assign')
                        ->schema([
                            Select::make('owner_id')
                                ->label('Deal owner')
                                ->options(fn (): array => OwnerOptions::forForm(null))
                                ->in(fn (): array => array_keys(OwnerOptions::forForm(null)))
                                ->searchable()
                                ->placeholder('No owner'),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $ownerId = filled($data['owner_id'] ?? null) ? (int) $data['owner_id'] : null;

                            $records->each(fn (Deal $deal) => $deal->update(['owner_id' => $ownerId]));

                            $count = $records->count();
                            $noun = Str::plural('deal', $count);

                            Notification::make()
                                ->title($ownerId === null
                                    ? "Removed the owner of {$count} {$noun}"
                                    : "Assigned {$count} {$noun} to ".OwnerOptions::forForm(null)[$ownerId])
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make()->authorizeIndividualRecords(),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No deals yet')
            ->emptyStateDescription('Create your first deal to start tracking your pipeline.');
    }
}
