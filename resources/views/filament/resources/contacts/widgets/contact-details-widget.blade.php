<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Contact Details</x-slot>

        <dl class="space-y-4">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Name</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $record->name }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Email</dt>
                <dd class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                    <a href="mailto:{{ $record->email }}" class="hover:underline">{{ $record->email }}</a>
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Phone</dt>
                <dd class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                    @if($record->phone)
                        <a href="tel:{{ $record->phone }}" class="hover:underline">{{ $record->phone }}</a>
                    @else
                        <span class="text-gray-400 dark:text-gray-600">—</span>
                    @endif
                </dd>
            </div>

            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Added</dt>
                <dd class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                    {{ $record->created_at->format('M j, Y') }}
                </dd>
            </div>

            @if($record->tags->isNotEmpty())
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Tags</dt>
                    <dd class="mt-2 flex flex-wrap gap-1.5">
                        @foreach($record->tags as $tag)
                            <x-filament::badge :color="\Filament\Support\Colors\Color::hex($tag->color ?? '#94a3b8')">
                                {{ $tag->name }}
                            </x-filament::badge>
                        @endforeach
                    </dd>
                </div>
            @endif

            <div class="border-t border-gray-100 pt-4 dark:border-gray-700">
                <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Activities</dt>
                <dd class="mt-1 text-sm font-semibold text-gray-900 dark:text-gray-100">
                    {{ $record->activities()->count() }}
                </dd>
            </div>

            @if($customFields->isNotEmpty())
                <div class="border-t border-gray-100 pt-4 dark:border-gray-700">
                    <dt class="mb-3 text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Additional Info</dt>
                    <div class="space-y-3">
                        @foreach($customFields as $field)
                            <div>
                                <dt class="text-xs text-gray-400 dark:text-gray-500">{{ $field['label'] }}</dt>
                                <dd class="mt-0.5 text-sm text-gray-700 dark:text-gray-300">{{ $field['value'] }}</dd>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
