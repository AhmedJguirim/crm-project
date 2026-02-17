<x-filament-panels::page.simple>
    @if (filament()->hasLogin())
        <x-filament-panels::form wire:submit="authenticate">
            {{ $this->form }}
 
            <x-filament-panels::form.actions
                :actions="$this->getCachedFormActions()"
                :full-width="$this->hasFullWidthFormActions()"
            />
        </x-filament-panels::form>
    @endif
</x-filament-panels::page.simple>