<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Deal Details</x-slot>

        <dl class="space-y-4">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Title</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $record->title }}</dd>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <x-filament::badge :color="$record->stage->getColor()" :icon="$record->stage->getIcon()">
                    {{ $record->stage->getLabel() }}
                </x-filament::badge>

                <x-filament::badge :color="$record->status->getColor()">
                    {{ $record->status->getLabel() }}
                </x-filament::badge>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Value</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ $formattedValue ?? '—' }}
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Contact</dt>
                <dd class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                    @if ($record->contact)
                        <a
                            href="{{ \App\Filament\Resources\Contacts\ContactResource::getUrl('view', ['record' => $record->contact]) }}"
                            class="font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            {{ $record->contact->name }}
                        </a>
                    @else
                        <span class="text-gray-400 dark:text-gray-600">No contact</span>
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Expected Close</dt>
                <dd class="mt-1 text-sm {{ $isOverdue ? 'font-semibold text-danger-600 dark:text-danger-400' : 'text-gray-700 dark:text-gray-300' }}">
                    @if ($record->expected_close_date)
                        {{ $record->expected_close_date->format('M j, Y') }}
                        @if ($isOverdue)
                            <span class="ml-1 text-xs font-medium uppercase tracking-wide">Overdue</span>
                        @endif
                    @else
                        <span class="text-gray-400 dark:text-gray-600">—</span>
                    @endif
                </dd>
            </div>

            @if ($isWon)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Won Date</dt>
                    <dd class="mt-1 text-sm font-medium text-success-700 dark:text-success-400">
                        {{ $record->won_at?->format('M j, Y \a\t g:i A') ?? '—' }}
                    </dd>
                </div>
            @endif

            @if ($isLost)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Lost Date</dt>
                    <dd class="mt-1 text-sm font-medium text-gray-600 dark:text-gray-400">
                        {{ $record->lost_at?->format('M j, Y \a\t g:i A') ?? '—' }}
                    </dd>
                </div>
            @endif

            <div class="border-t border-gray-100 pt-4 dark:border-gray-700">
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Notes</dt>
                @if (filled($record->notes))
                    <dd class="mt-1 max-h-48 overflow-y-auto whitespace-pre-line text-sm text-gray-700 dark:text-gray-300">{{ $record->notes }}</dd>
                @else
                    <dd class="mt-1 text-sm text-gray-400 dark:text-gray-600">No notes</dd>
                @endif
            </div>
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
