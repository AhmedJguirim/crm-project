<div class="flex items-center gap-2">
    <div class="flex items-center gap-1.5">
        <label class="sr-only" for="date-from">From</label>
        <input
            id="date-from"
            type="date"
            min="{{ $earliestDate }}"
            max="{{ $latestDate }}"
            wire:model.live="dateFrom"
            class="fi-input h-8 rounded-lg border border-gray-300 bg-white px-3 text-xs text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
            placeholder="From"
        >
        <span class="text-xs text-gray-400">—</span>
        <label class="sr-only" for="date-to">To</label>
        <input
            id="date-to"
            type="date"
            min="{{ $earliestDate }}"
            max="{{ $latestDate }}"
            wire:model.live="dateTo"
            class="fi-input h-8 rounded-lg border border-gray-300 bg-white px-3 text-xs text-gray-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100"
            placeholder="To"
        >
    </div>

    @if($hasFilters)
        <x-filament::button
            wire:click="clearFilters"
            color="gray"
            size="xs"
            icon="heroicon-m-x-mark"
        >
            Clear
        </x-filament::button>
    @endif
</div>
