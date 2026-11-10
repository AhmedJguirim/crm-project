<fieldset class="mb-3">
    <legend class="sr-only">Activity type</legend>

    <div class="flex flex-wrap gap-1.5">
        @foreach($activityTypeOptions as $type)
            @php
                $isSelected = in_array($type->value, $selectedActivityTypes, true);
            @endphp
            <label @class([
                'inline-flex cursor-pointer items-center rounded-full border px-2.5 py-0.5 text-xs font-medium',
                'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $isSelected,
                'border-gray-300 text-gray-600 dark:border-gray-600 dark:text-gray-300' => ! $isSelected,
            ])>
                <input
                    type="checkbox"
                    class="sr-only"
                    wire:model.live="activityTypes"
                    value="{{ $type->value }}"
                    @checked($isSelected)
                >
                {{ $type->getLabel() }}
            </label>
        @endforeach
    </div>
</fieldset>
