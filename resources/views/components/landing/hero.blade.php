@props(['loginUrl', 'registerUrl', 'appUrl' => null])

@php
    $ring = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';
    $solid = 'inline-flex w-1/2 items-center justify-center rounded-full bg-primary-600 px-4 py-4 text-center text-sm font-bold text-white shadow-lg shadow-primary-950/30 transition hover:bg-primary-700 sm:w-auto sm:px-8 sm:text-base '.$ring;
    $glass = 'inline-flex w-1/2 items-center justify-center rounded-full border border-white/25 bg-white/10 px-4 py-4 text-center text-sm font-bold text-white transition hover:bg-white/15 sm:w-auto sm:px-8 sm:text-base sm:backdrop-blur-sm '.$ring;
@endphp

<section id="top" class="relative flex min-h-screen items-end overflow-hidden bg-primary-950">
    <div aria-hidden="true" class="absolute inset-0 bg-linear-to-t from-primary-950 via-primary-950/75 to-primary-900/40"></div>
    <div aria-hidden="true" class="absolute inset-0 bg-linear-to-r from-primary-950/80 via-primary-950/40 to-transparent"></div>

    <div class="relative w-full">
        <div class="landing-container pb-16 pt-32 sm:pb-24 sm:pt-36 lg:pb-28 lg:pt-40">
            <div class="grid items-end gap-12 lg:grid-cols-2">
                <div class="max-w-2xl">
                    <span class="landing-section-label dark:bg-primary-500/10 dark:text-primary-300">
                        <span class="h-1.5 w-1.5 rounded-full bg-primary-500" aria-hidden="true"></span>
                        CRM Software for small teams and solo entrepreneurs
                    </span>

                    <h1 class="mt-6 text-4xl font-black leading-tight tracking-tight text-white sm:text-6xl">Your clients, deals and invoices, <span class="text-primary-400">in one place.</span></h1>

                    <p class="mt-5 max-w-xl text-base leading-7 text-white/75 sm:mt-6 sm:text-lg sm:leading-8">Keep your data centralized and organized in the CRM that is made for you. If you're a freelancer, a solo entrepreneur or a small development team. This solution is made and maintained with your needs in mind.</p>

                    <div class="mt-8 flex flex-nowrap gap-3 sm:mt-10 sm:flex-wrap sm:gap-4">
                        @if ($appUrl)
                            <a href="{{ $appUrl }}" class="{{ $solid }}">Open the app</a>
                        @else
                            <a href="{{ $registerUrl }}" class="{{ $solid }}">Get started</a>
                        @endif
                        <a href="#features" class="{{ $glass }}">See the features</a>
                    </div>
                </div>

                <div data-hero-visual class="hidden rotate-2 rounded-[1.6rem] border border-white/15 bg-white/10 p-3 shadow-2xl shadow-primary-950/40 backdrop-blur-md lg:block">
                    <div aria-hidden="true" class="flex gap-1.5 px-2 pb-3">
                        <span class="h-2.5 w-2.5 rounded-full bg-white/30"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-white/30"></span>
                        <span class="h-2.5 w-2.5 rounded-full bg-white/30"></span>
                    </div>
                    <picture class="block">
                        <source media="(min-width: 64rem)" srcset="{{ asset('images/landing/board-720.webp') }} 720w, {{ asset('images/landing/board-1440.webp') }} 1440w" sizes="(min-width: 1280px) 600px, 47vw">
                        <img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" width="720" height="450" alt="The deals board, with a column per pipeline stage and a card for each deal." fetchpriority="high" decoding="async" class="block h-auto w-full rounded-[1.2rem] bg-white">
                    </picture>
                </div>
            </div>
        </div>

        <a href="#features" aria-label="Scroll to the features" class="absolute bottom-6 left-1/2 hidden -translate-x-1/2 items-center justify-center rounded-full border border-white/20 bg-white/10 p-3 text-white/70 transition hover:bg-white/20 sm:flex sm:backdrop-blur-sm {{ $ring }}">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5 animate-bounce motion-reduce:animate-none" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </a>
    </div>
</section>
