<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Recent Activities</x-slot>

        @if ($activities->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No recent activities yet.</p>
        @else
            <div class="space-y-3">
                @foreach ($activities as $activity)
                    <div class="flex items-start justify-between gap-4 rounded-lg border border-gray-200 bg-white px-4 py-3 dark:border-gray-700 dark:bg-gray-800/50">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge :color="$activity->type->getColor()">
                                    {{ $activity->type->getLabel() }}
                                </x-filament::badge>

                                @if ($activity->contact)
                                    <a
                                        href="{{ \App\Filament\Resources\Contacts\ContactResource::getUrl('view', ['record' => $activity->contact]) }}"
                                        class="truncate text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                                    >
                                        {{ $activity->contact->name }}
                                    </a>
                                @else
                                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Deleted Contact</span>
                                @endif
                            </div>

                            @if (filled($activity->subject))
                                <p class="mt-1 truncate text-sm text-gray-700 dark:text-gray-300">{{ $activity->subject }}</p>
                            @endif
                        </div>

                        <p class="shrink-0 text-xs text-gray-500 dark:text-gray-400">
                            {{ $activity->occurred_at->format('M j, Y g:i A') }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-4 border-t border-gray-100 pt-3 dark:border-gray-700">
            <a href="{{ $contactsUrl }}" class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                View all contacts
                <x-filament::icon icon="heroicon-o-arrow-right" class="h-4 w-4" />
            </a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
