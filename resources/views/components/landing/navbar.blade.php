@props(['loginUrl', 'registerUrl', 'appUrl' => null])

@php
    $ring = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600';
    $desktopLink = 'rounded-md text-sm font-medium text-gray-600 transition hover:text-primary-600 dark:text-gray-300 dark:hover:text-primary-300 '.$ring;
    $drawerLink = 'rounded-2xl px-4 py-3 text-gray-700 transition hover:bg-primary-50 hover:text-primary-700 dark:text-gray-200 dark:hover:bg-primary-950/30 dark:hover:text-primary-200 '.$ring;
    $ghost = 'inline-flex h-11 items-center justify-center rounded-full px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-800 '.$ring;
    $primary = 'inline-flex h-11 items-center justify-center rounded-full bg-primary-600 px-5 text-sm font-semibold text-white shadow-lg shadow-primary-500/30 transition hover:bg-primary-500 '.$ring;
@endphp

<header data-landing-navbar data-scrolled="false" class="group fixed inset-x-0 top-0 z-50">
    <nav aria-label="Main" class="landing-container pt-4">
        <div class="flex items-center justify-between gap-3 rounded-full border border-white/60 bg-white/80 px-4 py-3 shadow-lg shadow-primary-950/5 backdrop-blur-lg transition-shadow duration-300 group-data-[scrolled=true]:shadow-xl group-data-[scrolled=true]:shadow-primary-950/10 md:px-6 dark:border-white/10 dark:bg-gray-950/80">
            <a href="{{ route('home') }}" class="rounded-md text-lg font-black tracking-tight {{ $ring }}">CRM</a>

            <div class="hidden items-center gap-8 lg:flex">
                <a href="#features" class="{{ $desktopLink }}">Features</a>
                <a href="#pipeline" class="{{ $desktopLink }}">Pipeline</a>
                <a href="#reports" class="{{ $desktopLink }}">Reports</a>
                <a href="#teams" class="{{ $desktopLink }}">Teams</a>
            </div>

            <div class="flex items-center gap-2">
                <button type="button" data-theme-toggle aria-label="Toggle dark mode" class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-600 hover:bg-gray-100 dark:border-white/10 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 {{ $ring }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 dark:hidden" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 0 0 9.002-5.998Z" />
                    </svg>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="hidden h-5 w-5 dark:block" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z" />
                    </svg>
                </button>

                <div class="hidden items-center gap-2 lg:flex">
                    @if ($appUrl)
                        <a href="{{ $appUrl }}" class="{{ $primary }}">Open the app</a>
                    @else
                        <a href="{{ $loginUrl }}" class="{{ $ghost }}">Log in</a>
                        <a href="{{ $registerUrl }}" class="{{ $primary }}">Get started</a>
                    @endif
                </div>

                <button type="button" data-menu-open aria-controls="mobile-menu" aria-expanded="false" aria-label="Open menu" class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-700 lg:hidden dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 {{ $ring }}">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
            </div>
        </div>
    </nav>
</header>

<div data-menu-backdrop data-open="false" aria-hidden="true" class="invisible fixed inset-0 z-40 bg-gray-950/50 opacity-0 transition-[opacity,visibility] duration-200 data-[open=true]:visible data-[open=true]:opacity-100 motion-reduce:transition-none lg:hidden"></div>

<div id="mobile-menu" role="dialog" aria-modal="true" aria-label="Menu" data-open="false" class="invisible fixed inset-y-0 end-0 z-50 flex w-full max-w-xs translate-x-full flex-col bg-white p-6 opacity-0 shadow-2xl transition-[opacity,translate,visibility] duration-200 ease-out data-[open=true]:visible data-[open=true]:translate-x-0 data-[open=true]:opacity-100 motion-reduce:transition-none lg:hidden dark:bg-gray-950">
    <div class="mb-6 flex items-center justify-between">
        <span class="text-lg font-black tracking-tight">CRM</span>
        <button type="button" data-menu-close aria-label="Close menu" class="inline-flex h-11 w-11 items-center justify-center rounded-full border border-gray-200 bg-white text-gray-700 dark:border-white/10 dark:bg-gray-900 dark:text-gray-200 {{ $ring }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav aria-label="Mobile" class="flex flex-1 flex-col gap-3 text-lg font-semibold">
        <a href="#features" data-menu-close class="{{ $drawerLink }}">Features</a>
        <a href="#pipeline" data-menu-close class="{{ $drawerLink }}">Pipeline</a>
        <a href="#reports" data-menu-close class="{{ $drawerLink }}">Reports</a>
        <a href="#teams" data-menu-close class="{{ $drawerLink }}">Teams</a>
    </nav>

    <div class="flex flex-col gap-3">
        @if ($appUrl)
            <a href="{{ $appUrl }}" class="{{ $primary }} w-full">Open the app</a>
        @else
            <a href="{{ $loginUrl }}" class="{{ $ghost }} w-full border border-gray-200 dark:border-white/10">Log in</a>
            <a href="{{ $registerUrl }}" class="{{ $primary }} w-full">Get started</a>
        @endif
    </div>
</div>
