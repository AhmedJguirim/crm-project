<?php

namespace App\Filament\Resources\Segments\RelationManagers;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only list of the segment members: membership is managed by the segment rules.
 */
class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Members';

    public function isReadOnly(): bool
    {
        return true;
    }

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

                TextColumn::make('joined_at')
                    ->label('Joined at')
                    ->state(fn (Contact $record) => $record->pivot?->created_at)
                    ->dateTime()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('contact_segment.created_at', $direction)),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('contact_segment.created_at', 'desc'))
            ->recordActions([
                Action::make('viewContact')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->url(fn (Contact $record): string => ContactResource::getUrl('view', ['record' => $record]))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('No members yet')
            ->emptyStateDescription('Contacts matching the published rules will appear here.');
    }
}
