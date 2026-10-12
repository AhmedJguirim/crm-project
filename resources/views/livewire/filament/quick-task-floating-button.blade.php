<div class="pointer-events-none fixed bottom-6 right-6 z-50">
    @can('create', \App\Models\Task::class)
    <x-filament::button
        class="pointer-events-auto rounded-full shadow-lg"
        :icon="\Filament\Support\Icons\Heroicon::OutlinedPlus"
        color="primary"
        size="lg"
        title="Quick Task"
        wire:click="mountAction('quickTask')"
    >
        Quick Task
    </x-filament::button>
    @endcan

    <x-filament-actions::modals />
</div>
