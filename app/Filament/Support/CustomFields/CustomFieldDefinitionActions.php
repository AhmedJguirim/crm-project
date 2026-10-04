<?php

namespace App\Filament\Support\CustomFields;

use App\Filament\Support\SegmentUsageGuard;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;

/**
 * Soft delete and restore actions shared by the contact and company custom field resources.
 *
 * Deleting a definition only hides it: the values already filled in on records stay
 * stored, and reappear when the definition is restored.
 */
class CustomFieldDefinitionActions
{
    /**
     * @param  string  $recordsLabel  plural label of the records holding the values (contacts, companies)
     */
    public static function delete(string $recordsLabel): DeleteAction
    {
        return SegmentUsageGuard::protectDelete(DeleteAction::make())
            ->requiresConfirmation()
            ->modalHeading(fn ($record): string => "Delete the \"{$record->name}\" field?")
            ->modalDescription(static::deleteDescription($recordsLabel, 'This field'))
            ->modalSubmitActionLabel('Yes, delete the field')
            ->successNotificationTitle('Custom field deleted. Its data is kept and returns if you restore it.');
    }

    public static function deleteBulk(string $recordsLabel): DeleteBulkAction
    {
        return SegmentUsageGuard::protectBulkDelete(DeleteBulkAction::make()->authorizeIndividualRecords())
            ->requiresConfirmation()
            ->modalHeading('Delete the selected fields?')
            ->modalDescription(static::deleteDescription($recordsLabel, 'These fields'))
            ->modalSubmitActionLabel('Yes, delete the fields')
            ->successNotificationTitle('Custom fields deleted. Their data is kept and returns if you restore them.');
    }

    public static function restore(string $recordsLabel): RestoreAction
    {
        return RestoreAction::make()
            ->modalDescription("The field will show up again on your {$recordsLabel}, with all the values that were filled in before it was deleted.");
    }

    public static function restoreBulk(string $recordsLabel): RestoreBulkAction
    {
        return RestoreBulkAction::make()
            ->authorizeIndividualRecords()
            ->modalDescription("The fields will show up again on your {$recordsLabel}, with all the values that were filled in before they were deleted.");
    }

    protected static function deleteDescription(string $recordsLabel, string $subject): string
    {
        return "{$subject} will be hidden from your {$recordsLabel}. "
            .'No data is lost: everything already filled in stays safely stored. '
            .'You can restore it at any time from the "Trashed" filter, and all of that data will reappear exactly as it was.';
    }
}
