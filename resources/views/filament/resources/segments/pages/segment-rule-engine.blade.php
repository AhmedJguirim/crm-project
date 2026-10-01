@php
    $rules = $this->rules();
    $selectedRule = $this->selectedRule();
@endphp

<x-filament-panels::page>
    @if ($rules->isEmpty())
        <x-filament::section heading="No Rules Found">
            <x-slot name="afterHeader">
                {{ $this->createRuleAction }}
            </x-slot>

            <p class="text-sm text-gray-600 dark:text-gray-400">
                Create your first rule to start defining conditions for this segment.
            </p>
        </x-filament::section>
    @else
        <x-filament::section heading="Rules list">
            <x-slot name="description">
                A contact enters the segment if it matches <span class="font-semibold">ANY</span> of the rules.
                The current rules match
                <span class="font-semibold text-primary-600 dark:text-primary-400">{{ number_format($this->segmentMatchCount()) }}</span>
                {{ str('contact')->plural($this->segmentMatchCount()) }}.
            </x-slot>

            <x-slot name="afterHeader">
                <div class="flex flex-wrap items-center gap-2">
                    {{ $this->cancelChangesAction }}
                    {{ $this->saveChangesAction }}
                    {{ $this->createRuleAction }}
                </div>
            </x-slot>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
                <div class="flex flex-col gap-2 lg:border-e lg:border-gray-200 lg:pe-4 dark:lg:border-white/10">
                    @foreach ($rules as $rule)
                        @php
                            $isSelected = $selectedRule?->id === $rule->id;
                            $matchCount = $this->ruleMatchCount($rule);
                        @endphp

                        <button
                            type="button"
                            wire:key="rule-{{ $rule->id }}"
                            wire:click="selectRule('{{ $rule->id }}')"
                            @class([
                                'flex flex-col gap-1 rounded-lg border p-3 text-start transition',
                                'border-primary-500 bg-primary-50 dark:border-primary-400 dark:bg-primary-500/10' => $isSelected,
                                'border-gray-200 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5' => ! $isSelected,
                            ])
                        >
                            <span class="font-medium text-gray-950 dark:text-white">{{ $rule->name }}</span>

                            <span class="flex flex-wrap items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ count($rule->conditions) }} {{ str('condition')->plural(count($rule->conditions)) }}

                                @if ($matchCount === null)
                                    <x-filament::badge color="warning" size="sm">Incomplete</x-filament::badge>
                                @else
                                    <x-filament::badge color="gray" size="sm">
                                        {{ number_format($matchCount) }} {{ str('match')->plural($matchCount) }}
                                    </x-filament::badge>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>

                <div class="lg:col-span-4">
                    @if ($selectedRule)
                        <x-filament::section
                            wire:key="selected-rule-{{ $selectedRule->id }}"
                            :heading="'Rule \'' . $selectedRule->name . '\' conditions set'"
                            description="The contact matches this rule if ALL of the conditions are met."
                            secondary
                        >
                            <x-slot name="afterHeader">
                                <div class="flex items-center gap-2">
                                    {{ ($this->renameRuleAction)(['rule' => $selectedRule->id]) }}
                                    {{ ($this->deleteRuleAction)(['rule' => $selectedRule->id]) }}
                                </div>
                            </x-slot>

                            <div class="flex flex-col gap-3">
                                @forelse ($selectedRule->conditions as $condition)
                                    <div
                                        wire:key="condition-{{ $condition->id }}"
                                        @class([
                                            'flex items-center justify-between gap-4 rounded-lg border bg-white p-3 dark:bg-gray-900',
                                            'border-gray-200 dark:border-white/10' => $this->isConditionComplete($condition),
                                            'border-warning-400 dark:border-warning-500' => ! $this->isConditionComplete($condition),
                                        ])
                                    >
                                        <div class="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-200">
                                            <x-filament::icon
                                                :icon="$condition->type->getIcon()"
                                                class="h-5 w-5 shrink-0 text-gray-400"
                                            />

                                            <span>{{ $this->describe($condition) }}</span>

                                            @unless ($this->isConditionComplete($condition))
                                                <x-filament::badge color="warning" size="sm">Incomplete</x-filament::badge>
                                            @endunless
                                        </div>

                                        <div class="flex shrink-0 items-center gap-1">
                                            {{ ($this->editConditionAction)(['rule' => $selectedRule->id, 'condition' => $condition->id]) }}
                                            {{ ($this->deleteConditionAction)(['rule' => $selectedRule->id, 'condition' => $condition->id]) }}
                                        </div>
                                    </div>

                                    @unless ($loop->last)
                                        <p class="ms-2 select-none text-sm font-medium uppercase text-gray-500 dark:text-gray-400">And</p>
                                    @endunless
                                @empty
                                    <p class="text-sm text-gray-500 dark:text-gray-400">
                                        This rule has no conditions yet. Add one to start matching contacts.
                                    </p>
                                @endforelse

                                <div>
                                    {{ ($this->addConditionAction)(['rule' => $selectedRule->id]) }}
                                </div>
                            </div>
                        </x-filament::section>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">Select a rule to edit its conditions.</p>
                    @endif
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
