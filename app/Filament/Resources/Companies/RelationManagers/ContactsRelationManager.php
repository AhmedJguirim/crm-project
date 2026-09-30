<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable(),

                TextColumn::make('phone')
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->badge()
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (Contact $record): string => ContactResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->multiple()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->where('contacts.organization_id', Filament::getTenant()?->getKey())),
            ])
            ->recordActions([
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
