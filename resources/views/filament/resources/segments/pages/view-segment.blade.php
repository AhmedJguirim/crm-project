<x-filament-panels::page>
    @if ($this->record->is_syncing)
        <div wire:poll.5s="checkSyncStatus" class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
            <x-filament::loading-indicator class="h-4 w-4" />
            Computing segment members…
        </div>
    @endif

    {{ $this->content }}
</x-filament-panels::page>
