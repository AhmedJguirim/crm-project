@props(['title'])

<article class="rounded-[1.6rem] border border-gray-200/70 bg-white p-6 shadow-sm shadow-primary-950/5 transition duration-300 hover:-translate-y-1 hover:shadow-lg hover:shadow-primary-950/10 motion-reduce:transition-none motion-reduce:hover:translate-y-0 dark:border-white/10 dark:bg-gray-950">
    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-primary-50 text-primary-600 dark:bg-primary-500/10 dark:text-primary-300">{{ $icon }}</div>

    <h3 class="mt-5 text-lg font-bold leading-7 text-gray-900 dark:text-white">{{ $title }}</h3>

    <p class="mt-2 text-sm leading-6 text-gray-600 dark:text-gray-300">{{ $slot }}</p>
</article>
