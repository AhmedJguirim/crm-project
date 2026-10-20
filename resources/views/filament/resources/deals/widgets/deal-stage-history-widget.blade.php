<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Stage history</x-slot>

        @if ($changes->isEmpty())
            <p class="text-sm text-gray-400 dark:text-gray-500">No stage changes recorded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 text-left text-xs font-medium uppercase tracking-wide text-gray-400 dark:border-gray-700 dark:text-gray-500">
                            <th class="pb-2 pr-4">From</th>
                            <th class="pb-2 pr-4">To</th>
                            <th class="pb-2 pr-4">By</th>
                            <th class="pb-2">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50 dark:divide-gray-800">
                        @foreach ($changes as $change)
                            <tr wire:key="stage-change-{{ $change->id }}">
                                <td class="py-2 pr-4">
                                    @if ($change->fromStage)
                                        <x-filament::badge :color="$change->fromStage->color ?? 'gray'">{{ $change->fromStage->name }}</x-filament::badge>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if ($change->toStage)
                                        <x-filament::badge :color="$change->toStage->color ?? 'gray'">{{ $change->toStage->name }}</x-filament::badge>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-600">Deleted stage</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-600 dark:text-gray-400">
                                    @if ($change->changedBy)
                                        {{ $change->changedBy->name }}
                                    @else
                                        <span class="text-gray-400 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                                <td class="py-2 text-gray-600 dark:text-gray-400">
                                    {{ \App\Support\LocalTime::format($change->changed_at, 'M j, Y \a\t g:i A') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($changes->hasPages())
                <div class="mt-3">
                    {{ $changes->links() }}
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
