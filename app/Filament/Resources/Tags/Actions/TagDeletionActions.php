<?php

namespace App\Filament\Resources\Tags\Actions;

use App\Filament\Support\SegmentUsageGuard;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;

/**
 * Soft delete and restore actions of tags.
 *
 * Deleting a tag only hides it: contacts keep it attached, and get it back when the tag is restored.
 */
class TagDeletionActions
{
    public static function delete(): DeleteAction
    {
        return SegmentUsageGuard::protectDelete(DeleteAction::make())
            ->requiresConfirmation()
            ->modalHeading(fn ($record): string => "Delete the \"{$record->name}\" tag?")
            ->modalDescription('This tag will be hidden from your contacts, filters and segments. '
                .'Contacts keep it attached: restore it at any time from the "Trashed" filter and it reappears on all of them.')
            ->modalSubmitActionLabel('Yes, delete the tag')
            ->successNotificationTitle('Tag deleted. Contacts keep it and get it back if you restore it.');
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return SegmentUsageGuard::protectBulkDelete(DeleteBulkAction::make())
            ->requiresConfirmation()
            ->modalHeading('Delete the selected tags?')
            ->modalDescription('These tags will be hidden from your contacts, filters and segments. '
                .'Contacts keep them attached: restore them at any time from the "Trashed" filter and they reappear on all of them.')
            ->modalSubmitActionLabel('Yes, delete the tags')
            ->successNotificationTitle('Tags deleted. Contacts keep them and get them back if you restore them.');
    }

    public static function restore(): RestoreAction
    {
        return RestoreAction::make()
            ->modalDescription('The tag will show up again on every contact it was attached to.');
    }

    public static function restoreBulk(): RestoreBulkAction
    {
        return RestoreBulkAction::make()
            ->modalDescription('The tags will show up again on every contact they were attached to.');
    }
}
