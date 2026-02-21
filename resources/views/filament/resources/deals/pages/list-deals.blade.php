<x-filament-panels::page>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div class="inline-flex rounded-lg border border-gray-200 bg-white p-1 dark:border-gray-700 dark:bg-gray-900">
                <x-filament::button
                    wire:click="showBoard"
                    size="sm"
                    :color="$viewMode === 'board' ? 'primary' : 'gray'"
                    class="shadow-none!"
                >
                    Board
                </x-filament::button>

                <x-filament::button
                    wire:click="showTable"
                    size="sm"
                    :color="$viewMode === 'table' ? 'primary' : 'gray'"
                    class="shadow-none!"
                >
                    Table
                </x-filament::button>
            </div>
        </div>

        @if ($viewMode === 'board')
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 2xl:grid-cols-6">
                @foreach ($this->getBoardColumns() as $column)
                    @php
                        $stage = $column['stage'];
                        $deals = $column['deals'];
                        $headerBadgeColor = $stage->getColor() ?? 'gray';
                    @endphp

                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex items-center justify-between gap-2">
                                <x-filament::badge :color="$headerBadgeColor" :icon="$stage->getIcon()">
                                    {{ $stage->getLabel() }}
                                </x-filament::badge>

                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $column['count'] }}
                                </span>
                            </div>
                        </x-slot>

                        <x-slot name="description">
                            @if ($column['deals']->whereNotNull('value')->isNotEmpty())
                                {{ number_format($column['total_value'], 2) }} USD
                            @else
                                —
                            @endif
                        </x-slot>

                        <div class="space-y-3">
                            @forelse ($deals as $deal)
                                @php
                                    $isWon = $deal->stage === \App\Enums\DealStage::Won;
                                    $isLost = $deal->stage === \App\Enums\DealStage::Lost;
                                @endphp

                                <div
                                    class="rounded-xl border p-3 transition hover:shadow-sm {{ $isWon ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-700/60 dark:bg-emerald-900/20' : ($isLost ? 'border-gray-300 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/70' : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900/40') }}"
                                >
                                    <a
                                        href="{{ \App\Filament\Resources\Deals\DealResource::getUrl('view', ['record' => $deal]) }}"
                                        class="block truncate text-sm font-semibold text-gray-900 hover:underline dark:text-gray-100"
                                    >
                                        {{ $deal->title }}
                                    </a>

                                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        @if ($deal->contact)
                                            <a
                                                href="{{ \App\Filament\Resources\Contacts\ContactResource::getUrl('view', ['record' => $deal->contact]) }}"
                                                class="hover:underline"
                                            >
                                                {{ $deal->contact->name }}
                                            </a>
                                        @else
                                            <span>No contact</span>
                                        @endif
                                    </div>

                                    @if (! is_null($deal->value))
                                        <p class="mt-2 text-sm font-medium text-gray-800 dark:text-gray-200">
                                            {{ number_format((float) $deal->value, 2) }} {{ $deal->currency }}
                                        </p>
                                    @endif

                                    @if ($deal->expected_close_date)
                                        <p class="mt-2 text-xs {{ $deal->expected_close_date->isPast() ? 'text-danger-600 dark:text-danger-400' : 'text-gray-500 dark:text-gray-400' }}">
                                            Close: {{ $deal->expected_close_date->format('M j, Y') }}
                                        </p>
                                    @endif
                                </div>
                            @empty
                                <div class="rounded-xl border border-dashed border-gray-300 p-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
                                    No deals in this stage
                                </div>
                            @endforelse
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        @else
            {{ $this->table }}
        @endif
    </div>
</x-filament-panels::page>
