<?php

namespace App\Filament\Support\CustomFields;

use App\Exceptions\DuplicateCustomFieldValueException;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

/**
 * For the create and edit pages of records with unique custom fields: a value taken between the form check and the
 * save (see `EnforcesUniqueCustomFieldValues`) becomes a notification and nothing is saved.
 */
trait HandlesDuplicateCustomFieldValues
{
    /**
     * @param  Closure(): Model  $save
     */
    protected function haltOnDuplicateCustomFieldValue(Closure $save): Model
    {
        try {
            return $save();
        } catch (DuplicateCustomFieldValueException $exception) {
            Notification::make()
                ->danger()
                ->title('Not saved')
                ->body($exception->getMessage())
                ->send();

            $this->halt();
        }
    }
}
