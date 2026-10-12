<?php

namespace App\Filament\Resources\Segments\Actions;

use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;

/**
 * Segments are deleted permanently: their rules and member list are removed, contacts are not affected.
 */
class SegmentDeletionActions
{
    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->requiresConfirmation()
            ->modalHeading(fn ($record): string => "Permanently delete the \"{$record->name}\" segment?")
            ->modalDescription('The segment, its rules and its member list will be removed for good. Contacts are not affected. This cannot be undone.')
            ->modalSubmitActionLabel('Yes, delete permanently');
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->authorizeIndividualRecords()
            ->requiresConfirmation()
            ->modalHeading('Permanently delete the selected segments?')
            ->modalDescription('The segments, their rules and their member lists will be removed for good. Contacts are not affected. This cannot be undone.')
            ->modalSubmitActionLabel('Yes, delete permanently');
    }
}
