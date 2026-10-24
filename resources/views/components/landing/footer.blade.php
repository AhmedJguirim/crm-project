@props(['loginUrl', 'registerUrl', 'appUrl' => null])

@php
    $ring = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white';
    $link = 'rounded-md transition hover:text-white '.$ring;
@endphp

<footer class="bg-gray-950 text-gray-400">
    <div class="landing-container py-16">
        <div class="grid gap-10 md:grid-cols-3">
            <div>
                <div class="text-xl font-black text-white">CRM</div>
                <p class="mt-3 max-w-xs text-sm leading-7">A simple CRM for consultants, freelancers and small sales teams.</p>
            </div>

            <div>
                <div class="text-sm font-black tracking-widest text-white uppercase">Product</div>
                <ul class="mt-5 flex flex-col gap-3 text-sm">
                    <li><a href="#features" class="{{ $link }}">Features</a></li>
                    {{-- <li><a href="#pipeline" class="{{ $link }}">Pipeline</a></li> --}}
                    <li><a href="#reports" class="{{ $link }}">Reports</a></li>
                    <li><a href="#teams" class="{{ $link }}">Teams</a></li>
                    <li><a href="#import" class="{{ $link }}">Import and export</a></li>
                </ul>
            </div>

            <div>
                <div class="text-sm font-black tracking-widest text-white uppercase">Account</div>
                <ul class="mt-5 flex flex-col gap-3 text-sm">
                    @if ($appUrl)
                        <li><a href="{{ $appUrl }}" class="{{ $link }}">Open the app</a></li>
                    @else
                        <li><a href="{{ $loginUrl }}" class="{{ $link }}">Log in</a></li>
                        <li><a href="{{ $registerUrl }}" class="{{ $link }}">Sign up</a></li>
                    @endif
                </ul>
            </div>
        </div>
    </div>

    <div class="border-t border-white/10">
        <div class="landing-container flex flex-col items-center justify-between gap-3 py-5 text-xs sm:flex-row">
            <span>© {{ now()->year }} CRM</span>
        </div>
    </div>
</footer>
