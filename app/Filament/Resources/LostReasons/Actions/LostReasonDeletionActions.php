<?php

namespace App\Filament\Resources\LostReasons\Actions;

use App\Filament\Resources\LostReasons\Schemas\LostReasonForm;
use App\Models\LostReason;
use App\Support\NotificationText;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Soft delete and restore actions of lost reasons.
 *
 * Deleting a reason only hides it: deals that already have it keep it, shown as "(deleted)", until it is restored.
 */
class LostReasonDeletionActions
{
    private const KEPT_BY_DEALS = 'The reason is no longer offered when a deal is lost. Deals that already have it keep it, shown as "(deleted)".';

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalHeading(fn (LostReason $record): string => "Delete the \"{$record->name}\" lost reason?")
            ->modalDescription(self::KEPT_BY_DEALS)
            ->modalSubmitActionLabel('Yes, delete the reason')
            ->successNotificationTitle('Lost reason deleted.');
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->authorizeIndividualRecords()
            ->requiresConfirmation()
            ->modalHeading('Delete the selected lost reasons?')
            ->modalDescription(self::KEPT_BY_DEALS)
            ->modalSubmitActionLabel('Yes, delete the reasons')
            ->successNotificationTitle('Lost reasons deleted.');
    }

    public static function restore(): RestoreAction
    {
        return RestoreAction::make()
            ->modalDescription('The reason is offered again when a deal is lost.')
            ->before(function (RestoreAction $action, LostReason $record): void {
                if (! self::nameIsTaken($record)) {
                    return;
                }

                Notification::make()->danger()->title(NotificationText::plain(self::nameTakenMessage($record)))->send();

                $action->halt();
            });
    }

    public static function restoreBulk(): RestoreBulkAction
    {
        return RestoreBulkAction::make()
            ->authorizeIndividualRecords()
            ->modalDescription('The reasons are offered again when a deal is lost.')
            ->using(function (RestoreBulkAction $action, Collection $records): void {
                $records->each(function (LostReason $record) use ($action): void {
                    if (self::nameIsTaken($record)) {
                        $action->reportBulkProcessingFailure('name_taken', message: fn (int $failureCount): string => $failureCount === 1
                            ? self::nameTakenMessage($record)
                            : "{$failureCount} reasons were kept deleted because an active reason with the same name already exists. Rename one of them first.");

                        return;
                    }

                    $record->restore() || $action->reportBulkProcessingFailure();
                });
            });
    }

    private static function nameIsTaken(LostReason $record): bool
    {
        return LostReasonForm::nameIsTaken($record->organization_id, $record->name, $record->getKey());
    }

    private static function nameTakenMessage(LostReason $record): string
    {
        return "A lost reason named \"{$record->name}\" already exists. Rename one of them first.";
    }
}
