<?php

namespace App\Observers;

use App\Models\CustomField;
use Filament\Facades\Filament;

class CustomFieldObserver
{
    /**
     * Handle the CustomField "created" event.
     */
    public function creating(CustomField $customField): void
    {
        if (! $customField->organization_id && Filament::getTenant()) {
            $customField->organization_id = Filament::getTenant()->id;
        }

        if (is_null($customField->order)) {
            $maxOrder = CustomField::where('organization_id', $customField->organization_id)->max('order') ?? 0;
            $customField->order = $maxOrder + 1;
        }
    }

    /**
     * Handle the CustomField "updated" event.
     */
    public function updated(CustomField $customField): void
    {
        //
    }

    /**
     * Handle the CustomField "deleted" event.
     */
    public function deleted(CustomField $customField): void
    {
        //
    }

    /**
     * Handle the CustomField "restored" event.
     */
    public function restored(CustomField $customField): void
    {
        //
    }

    /**
     * Handle the CustomField "force deleted" event.
     */
    public function forceDeleted(CustomField $customField): void
    {
        //
    }
}
