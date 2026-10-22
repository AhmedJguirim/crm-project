@props(['title'])

<div class="flex gap-4 py-5">
    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-300">{{ $icon }}</div>

    <div>
        <h3 class="text-base font-bold leading-7 text-gray-900 dark:text-white">{{ $title }}</h3>

        <p class="mt-1 text-sm leading-7 text-gray-600 dark:text-gray-300">{{ $slot }}</p>
    </div>
</div>
