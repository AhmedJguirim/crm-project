<?php

namespace App\Filament\Resources\CompanyTypes\Actions;

use App\Filament\Support\SegmentUsageGuard;
use App\Models\CompanyType;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Soft delete and restore actions of company types.
 *
 * Deleting a type only hides it: companies keep it, shown as "(deleted)", until it is restored or they get another type.
 */
class CompanyTypeDeletionActions
{
    private const KEPT_BY_COMPANIES = 'Companies of this type keep it: it shows as "(deleted)" on them until you restore it or change their type.';

    public static function delete(): DeleteAction
    {
        return SegmentUsageGuard::protectDelete(DeleteAction::make())
            ->requiresConfirmation()
            ->modalHeading(fn (CompanyType $record): string => "Delete the \"{$record->name}\" company type?")
            ->modalDescription(self::KEPT_BY_COMPANIES)
            ->modalSubmitActionLabel('Yes, delete the type')
            ->successNotificationTitle('Company type deleted.');
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return SegmentUsageGuard::protectBulkDelete(DeleteBulkAction::make()->authorizeIndividualRecords())
            ->requiresConfirmation()
            ->modalHeading('Delete the selected company types?')
            ->modalDescription(self::KEPT_BY_COMPANIES)
            ->modalSubmitActionLabel('Yes, delete the types')
            ->successNotificationTitle('Company types deleted.');
    }

    public static function restore(): RestoreAction
    {
        return RestoreAction::make()
            ->modalDescription('The type shows again on the companies that kept it, and in the type options.')
            ->before(function (RestoreAction $action, CompanyType $record): void {
                if (! self::nameIsTaken($record)) {
                    return;
                }

                Notification::make()->danger()->title(self::nameTakenMessage($record))->send();

                $action->halt();
            });
    }

    public static function restoreBulk(): RestoreBulkAction
    {
        return RestoreBulkAction::make()
            ->authorizeIndividualRecords()
            ->modalDescription('The types show again on the companies that kept them, and in the type options.')
            ->using(function (RestoreBulkAction $action, Collection $records): void {
                $records->each(function (CompanyType $record) use ($action): void {
                    if (self::nameIsTaken($record)) {
                        $action->reportBulkProcessingFailure('name_taken', message: fn (int $failureCount): string => $failureCount === 1
                            ? self::nameTakenMessage($record)
                            : "{$failureCount} types were kept deleted because an active type with the same name already exists. Rename one of them first.");

                        return;
                    }

                    $record->restore() || $action->reportBulkProcessingFailure();
                });
            });
    }

    private static function nameIsTaken(CompanyType $record): bool
    {
        return CompanyType::query()
            ->where('organization_id', $record->organization_id)
            ->whereRaw('lower(name) = ?', [mb_strtolower($record->name)])
            ->whereKeyNot($record->getKey())
            ->exists();
    }

    private static function nameTakenMessage(CompanyType $record): string
    {
        return "A company type named \"{$record->name}\" already exists. Rename one of them first.";
    }
}
