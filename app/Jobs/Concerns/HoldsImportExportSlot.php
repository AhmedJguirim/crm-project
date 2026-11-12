<?php

namespace App\Jobs\Concerns;

use App\Support\Tenancy\ImportExportLimits;

/**
 * Lets a job carry the slot of {@see ImportExportLimits} its action claimed, and give it back when it ends. The job
 * using it must have the `$organizationId` and `$userId` properties. A job built without `holdingSlot()` holds no slot
 * and releases nothing.
 */
trait HoldsImportExportSlot
{
    public ?string $importExportSlotKind = null;

    public ?string $importExportSlot = null;

    /**
     * @param  ImportExportLimits::IMPORTS|ImportExportLimits::EXPORTS  $kind
     */
    public function holdingSlot(string $kind, string $slot): static
    {
        $this->importExportSlotKind = $kind;
        $this->importExportSlot = $slot;

        return $this;
    }

    public function releaseImportExportSlot(): void
    {
        if ($this->importExportSlot === null) {
            return;
        }

        ImportExportLimits::release(
            $this->importExportSlotKind ?? ImportExportLimits::IMPORTS,
            $this->organizationId,
            $this->userId,
            $this->importExportSlot,
        );
    }
}
