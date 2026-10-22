@php
    $panel = \Filament\Facades\Filament::getPanel('admin');
@endphp
<x-layouts::landing>
    <header class="landing-container flex items-center justify-between gap-3 py-4">
        <a href="{{ route('home') }}" class="rounded-md text-lg font-extrabold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">CRM</a>

        <div class="flex items-center gap-2">
            <button type="button" data-theme-toggle aria-label="Toggle dark mode" class="inline-flex h-11 w-11 items-center justify-center rounded-full text-gray-600 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:text-gray-300 dark:hover:bg-gray-800">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 dark:hidden" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                </svg>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="hidden h-5 w-5 dark:block" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                </svg>
            </button>

            @guest
                <a href="{{ $panel->getLoginUrl() }}" class="inline-flex h-11 items-center rounded-full px-4 text-sm font-semibold text-gray-700 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:text-gray-200 dark:hover:bg-gray-800">Log in</a>
                <a href="{{ $panel->getRegistrationUrl() }}" class="inline-flex h-11 items-center rounded-full bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">Get started</a>
            @endguest

            @auth
                <a href="{{ $panel->getUrl() }}" class="inline-flex h-11 items-center rounded-full bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">Open the app</a>
            @endauth
        </div>
    </header>

    <main id="main">
        <div class="landing-section landing-container">
            <span class="landing-section-label dark:bg-primary-500/10 dark:text-primary-300">
                <span class="h-1.5 w-1.5 rounded-full bg-primary-500" aria-hidden="true"></span>
                CRM for consultants, freelancers and small sales teams
            </span>

            <h1 class="mt-6 text-4xl font-black tracking-tight sm:text-6xl">Your clients, deals and invoices, <span class="text-primary-600 dark:text-primary-400">in one place.</span></h1>

            <p class="mt-6 max-w-2xl text-lg text-gray-600 dark:text-gray-300">Keep every contact and company organized, move deals through your pipeline, follow up on time and see what you're owed — without a spreadsheet in sight.</p>
        </div>
    </main>
</x-layouts::landing>
