<?php

namespace App\Filament\Actions;

use App\Filament\Support\RecordKeyField;
use App\Services\Owners\OwnerOptions;
use App\Support\NotificationText;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The bulk "Assign owner" of the contacts and companies lists: gives the selected records to a member of the
 * organization, or clears their owner. The owner field is a guarded select, so a forged id is a validation error,
 * and every record is updated through its model so the model events (segment membership) fire.
 */
class AssignOwnerBulkAction
{
    public static function make(string $recordNoun, string $ownerLabel): BulkAction
    {
        return BulkAction::make('assignOwner')
            ->authorize('updateAny')
            ->authorizeIndividualRecords('update')
            ->label('Assign owner')
            ->icon(Heroicon::OutlinedUserCircle)
            ->modalHeading('Assign owner')
            ->modalSubmitActionLabel('Assign')
            ->schema([
                RecordKeyField::select('owner_id')
                    ->label($ownerLabel)
                    ->options(fn (): array => OwnerOptions::forForm(null))
                    ->in(fn (): array => array_keys(OwnerOptions::forForm(null)))
                    ->searchable()
                    ->placeholder('No owner'),
            ])
            ->action(function (Collection $records, array $data) use ($recordNoun): void {
                $ownerId = filled($data['owner_id'] ?? null) ? (int) $data['owner_id'] : null;

                $records->each(fn (Model $record) => $record->update(['owner_id' => $ownerId]));

                $count = $records->count();
                $noun = Str::plural($recordNoun, $count);

                Notification::make()
                    ->title(NotificationText::plain($ownerId === null
                        ? "Removed the owner of {$count} {$noun}"
                        : "Assigned {$count} {$noun} to ".OwnerOptions::forForm(null)[$ownerId]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
