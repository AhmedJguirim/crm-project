<?php

namespace App\Observers;

use App\Exceptions\UsedInSegmentsException;
use App\Models\CustomField;
use App\Services\Segments\SegmentUsage;
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
     * Keep the type and the options used by segment conditions from changing, as that would break those conditions.
     */
    public function updating(CustomField $customField): void
    {
        if (! $customField->isDirty(['type', 'options'])) {
            return;
        }

        $segments = SegmentUsage::segmentsUsing($customField);

        if ($segments->isEmpty()) {
            return;
        }

        if ($customField->isDirty('type')) {
            throw UsedInSegmentsException::cannotChangeType($customField, $segments);
        }

        $keptValues = collect($customField->options ?? [])->pluck('value')->map(fn (mixed $value): string => (string) $value)->all();
        $removedUsedOptions = array_diff_key(SegmentUsage::usedOptionValues($customField), array_flip($keptValues));

        if ($removedUsedOptions !== []) {
            throw UsedInSegmentsException::cannotRemoveOptions($customField, $removedUsedOptions);
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
