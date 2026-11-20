<?php

namespace App\Support\Livewire;

use Livewire\Component;

/**
 * Livewire lets the browser call every public method of a component, and returns the result JSON-encoded. Filament
 * defines public getters that return whole records (every non-hidden column plus loaded relations), for example
 * `getTableRecord()` on a table host. Filament calls them from PHP only; its JavaScript never calls them, so a browser
 * call to one of them is a forged call and is refused with a 419 and one security log line before the method runs
 * (BUG-90). PHP calls never go through Livewire's `call` event and are untouched.
 *
 * A name is added to the list only after checking that `vendor/filament/*` resources and dist do not call it from the
 * browser.
 */
final class RefuseInternalMethodCalls
{
    public const INTERNAL_METHODS = [
        'getTableRecord',
        'getTableRecords',
        'getSelectedTableRecords',
        'getMountedTableActionRecord',
        'getOwnerRecord',
        'getRecord',
    ];

    /**
     * @param  array<int|string, mixed>  $params
     */
    public function __invoke(Component $component, string $method, array $params): void
    {
        if (! in_array($method, self::INTERNAL_METHODS, true)) {
            return;
        }

        ForgedPayload::refuse('internal_method', $component, $method);
    }
}
