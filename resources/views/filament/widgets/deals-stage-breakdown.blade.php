<div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Open Deals by Stage</p>

    <div class="mt-3 flex flex-wrap gap-2">
        @foreach ($breakdown as $stageLabel => $count)
            <x-filament::badge color="gray">
                {{ $stageLabel }}: {{ $count }}
            </x-filament::badge>
        @endforeach
    </div>
</div>
