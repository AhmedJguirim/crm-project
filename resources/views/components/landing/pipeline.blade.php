<section id="pipeline" aria-labelledby="pipeline-heading" class="landing-section">
    <div class="landing-container">
        <div class="grid items-center gap-12 lg:grid-cols-2 lg:gap-16">
            <div class="relative">
                <div data-pipeline-visual class="rounded-[2.5rem] bg-linear-to-br from-primary-100 via-white to-primary-50 p-3 shadow-xl shadow-primary-950/5 sm:p-4 dark:from-primary-500/10 dark:via-gray-900 dark:to-gray-950">
                    <img src="{{ asset('images/landing/deal-720.webp') }}" srcset="{{ asset('images/landing/deal-720.webp') }} 720w, {{ asset('images/landing/deal-1440.webp') }} 1440w" sizes="(min-width: 1280px) 600px, (min-width: 1024px) 47vw, 100vw" width="720" height="450" alt="A deal page showing the deal's stage, time in stage, value and expected close date." loading="lazy" decoding="async" class="block h-auto w-full rounded-[2rem] border border-gray-200/70 bg-white dark:border-white/10">
                </div>

                <div class="relative mx-4 -mt-10 rounded-[1.5rem] border border-gray-200/70 bg-white/95 p-4 shadow-lg shadow-primary-950/10 sm:mx-8 sm:p-5 dark:border-white/10 dark:bg-gray-900/95">
                    <div class="flex items-center justify-between gap-3 text-sm">
                        <span class="font-semibold text-gray-900 dark:text-white">Proposal Sent</span>
                        <span class="text-gray-600 dark:text-gray-300">50% win probability</span>
                    </div>

                    <div aria-hidden="true" class="mt-3 h-2 rounded-full bg-gray-100 dark:bg-white/10">
                        <div class="h-2 w-1/2 rounded-full bg-linear-to-r from-primary-400 to-primary-600"></div>
                    </div>
                </div>
            </div>

            <div>
                <span class="landing-section-label dark:bg-primary-500/10 dark:text-primary-300">
                    <span class="h-1.5 w-1.5 rounded-full bg-primary-500" aria-hidden="true"></span>
                    Pipeline
                </span>

                <h2 id="pipeline-heading" class="mt-4 text-[2rem] font-extrabold leading-tight text-gray-900 sm:text-[2.15rem] dark:text-white">A pipeline you can <span class="text-primary-600 dark:text-primary-400">see</span></h2>

                <p class="mt-3 text-sm leading-7 text-gray-600 sm:text-[15px] dark:text-gray-300">See where every deal stands and what it needs next.</p>

                <div class="mt-6">
                <x-landing.pipeline-row title="Drag deals across stages">
                    <x-slot:icon><svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15m-10.875 0h15.75c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H4.125C3.504 4.5 3 5.004 3 5.625v12.75c0 .621.504 1.125 1.125 1.125Z"/></svg></x-slot:icon>
                    A board with a column per stage, the total and weighted value under each header, and a side drawer to move, edit or log activity without leaving the board.
                </x-landing.pipeline-row>
                <div aria-hidden="true" class="h-px bg-gray-100 dark:bg-white/10"></div>
                <x-landing.pipeline-row title="Stages that fit your process">
                    <x-slot:icon><svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/></svg></x-slot:icon>
                    Admins can rename, recolor, reorder or add stages, each with a win probability. Every new workspace starts with Lead, Discovery, Proposal Sent, Negotiating, Won and Lost.
                </x-landing.pipeline-row>
                <div aria-hidden="true" class="h-px bg-gray-100 dark:bg-white/10"></div>
                <x-landing.pipeline-row title="Know why deals stall or slip away">
                    <x-slot:icon><svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true" data-slot="icon"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg></x-slot:icon>
                    Time in stage on each card, a stage history on each deal, a "Needs attention" list, and an optional lost reason when a deal is lost.
                </x-landing.pipeline-row>
                </div>
            </div>
        </div>
    </div>
</section>
