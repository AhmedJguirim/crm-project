<x-filament-widgets::widget>
    @if (! $isDismissed)
        <x-filament::section>
            <x-slot name="heading">Get started</x-slot>
            <x-slot name="description">{{ $doneCount }} of 4 done. Each step ticks itself once it's done.</x-slot>
            <x-slot name="afterHeader">{{ $this->dismissAction }}</x-slot>

            <ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($steps as $step)
                    <li class="flex items-center gap-2 text-sm">
                        @if ($step['done'])
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::CheckCircle" class="size-5 text-success-600 dark:text-success-400" />
                            <span class="text-gray-500 line-through dark:text-gray-400">{{ $step['label'] }}</span>
                            <span class="sr-only">(done)</span>
                        @else
                            <span class="size-5 rounded-full border-2 border-gray-300 dark:border-gray-600"></span>
                            @if ($step['url'])
                                <a href="{{ $step['url'] }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $step['label'] }}</a>
                            @else
                                <span>{{ $step['label'] }}</span>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-widgets::widget>
