<?php

namespace App\Filament\Resources\Companies\RelationManagers;

use App\Filament\Resources\Contacts\ContactResource;
use App\Jobs\ResyncContactSegments;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

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
                    ->authorize(fn (): bool => Auth::user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->preloadRecordSelect()
                    ->multiple()
                    ->recordSelectSearchColumns(['name', 'email'])
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => $query
                        ->where('contacts.organization_id', Filament::getTenant()?->getKey()))
                    ->after(fn (array $data) => $this->resyncContacts(Arr::wrap($data['recordId']))),
            ])
            ->recordActions([
                DetachAction::make()
                    ->authorize(fn (): bool => Auth::user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->after(fn (Contact $record) => $this->resyncContacts([$record->getKey()])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()
                        ->authorize(fn (): bool => Auth::user()?->can('update', $this->getOwnerRecord()) ?? false)
                        ->after(fn (Collection $records) => $this->resyncContacts($records->modelKeys())),
                ]),
            ]);
    }

    /**
     * Changing the contacts of a company moves them in and out of the segments with company conditions.
     *
     * @param  array<int, int|string>  $contactIds
     */
    private function resyncContacts(array $contactIds): void
    {
        ResyncContactSegments::dispatchForContacts($this->getOwnerRecord()->organization_id, array_map('intval', $contactIds));
    }
}
