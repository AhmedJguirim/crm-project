<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Activity Log</x-slot>

        @if ($activities->isEmpty())
            <div class="flex flex-col items-center justify-center py-8 text-center">
                <div class="mb-3 rounded-full bg-gray-100 p-3 dark:bg-gray-800">
                    <x-filament::icon
                        icon="heroicon-o-chat-bubble-left-right"
                        class="h-6 w-6 text-gray-400 dark:text-gray-500"
                    />
                </div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No activities logged yet</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">Use the "Log Activity" button above to add the first interaction.</p>
            </div>
        @else
            <div class="relative">
                <div class="absolute bottom-5 left-4 top-5 w-px bg-gray-200 dark:bg-gray-700"></div>

                <div class="space-y-0">
                    @foreach ($activities as $activity)
                        @php
                            $typeColorMap = [
                                'call' => ['dot' => 'bg-sky-500', 'badge' => 'sky'],
                                'email' => ['dot' => 'bg-blue-500', 'badge' => 'blue'],
                                'meeting' => ['dot' => 'bg-amber-500', 'badge' => 'warning'],
                                'whatsapp' => ['dot' => 'bg-green-500', 'badge' => 'success'],
                                'message' => ['dot' => 'bg-cyan-500', 'badge' => 'info'],
                                'note' => ['dot' => 'bg-gray-400', 'badge' => 'gray'],
                                'in_person' => ['dot' => 'bg-rose-500', 'badge' => 'danger'],
                                'other' => ['dot' => 'bg-gray-400', 'badge' => 'gray'],
                            ];
                            $colors = $typeColorMap[$activity->type->value] ?? $typeColorMap['other'];
                        @endphp

                        <div class="relative flex gap-4 pb-5 last:pb-0">
                            <div class="relative z-10 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border-2 border-white bg-white dark:border-gray-800 dark:bg-gray-800">
                                <div class="flex h-7 w-7 items-center justify-center rounded-full {{ $colors['dot'] }}">
                                    <x-filament::icon :icon="$activity->type->getIcon()" class="h-3.5 w-3.5 text-white" />
                                </div>
                            </div>

                            <div class="flex-1 rounded-lg border border-gray-200 bg-white p-3.5 dark:border-gray-700 dark:bg-gray-800/50">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-filament::badge :color="$colors['badge']">
                                        {{ $activity->type->getLabel() }}
                                    </x-filament::badge>

                                    @if ($activity->outcome)
                                        <x-filament::badge :color="$activity->outcome->getColor()">
                                            {{ $activity->outcome->getLabel() }}
                                        </x-filament::badge>
                                    @endif

                                    <span class="ml-auto text-xs text-gray-400 dark:text-gray-500">
                                        {{ $activity->occurred_at->format('M j, Y \a\t g:i A') }}
                                        @if ($activity->duration_minutes)
                                            &middot; {{ $activity->duration_minutes }} min
                                        @endif
                                    </span>
                                </div>

                                @if ($activity->subject)
                                    <p class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $activity->subject }}</p>
                                @endif

                                @if ($activity->notes)
                                    <p class="mt-1 whitespace-pre-line text-sm text-gray-500 dark:text-gray-400">{{ $activity->notes }}</p>
                                @endif

                                @if ($activity->user)
                                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-600">Logged by {{ $activity->user->name }}</p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if ($activities->hasPages())
                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-gray-700">
                    {{ $activities->links() }}
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
