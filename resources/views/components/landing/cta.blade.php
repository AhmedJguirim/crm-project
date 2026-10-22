@props(['loginUrl', 'registerUrl', 'appUrl' => null])

@php
    $ring = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';
    $solid = 'inline-flex items-center justify-center rounded-full bg-white px-8 py-4 text-base font-bold text-primary-700 shadow-lg shadow-primary-950/30 transition hover:bg-primary-50 '.$ring;
    $glass = 'inline-flex items-center justify-center rounded-full border border-white/25 bg-white/10 px-8 py-4 text-base font-bold text-white transition hover:bg-white/15 '.$ring;
@endphp

<section id="get-started" aria-labelledby="get-started-heading" class="landing-section">
    <div class="landing-container">
        <div class="relative overflow-hidden rounded-[2rem] bg-linear-to-br from-primary-800 via-primary-700 to-primary-900 px-6 py-14 text-center shadow-2xl shadow-primary-950/20 sm:px-12 sm:py-20">
            <div aria-hidden="true" class="pointer-events-none absolute inset-0">
                <div class="absolute -top-16 start-1/4 h-48 w-48 rounded-full bg-white/10 blur-3xl"></div>
                <div class="absolute -bottom-20 end-1/4 h-56 w-56 rounded-full bg-primary-300/20 blur-3xl"></div>
            </div>

            <div class="relative mx-auto max-w-2xl">
                <h2 id="get-started-heading" class="text-[2rem] font-extrabold leading-tight text-white sm:text-[2.5rem]">Ready when you are.</h2>

                <p class="mt-4 text-base leading-7 text-white/90 sm:text-lg">{{ $appUrl ? 'Your pipeline is waiting.' : 'Create your account and your pipeline is waiting.' }}</p>

                <div class="mt-8 flex flex-wrap justify-center gap-3 sm:gap-4">
                    @if ($appUrl)
                        <a href="{{ $appUrl }}" class="{{ $solid }}">Open the app</a>
                    @else
                        <a href="{{ $registerUrl }}" class="{{ $solid }}">Get started</a>
                        <a href="{{ $loginUrl }}" class="{{ $glass }}">Log in</a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
