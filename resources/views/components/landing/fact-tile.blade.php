@props(['index', 'value', 'label', 'detail'])

<div class="group relative overflow-hidden rounded-[1.5rem] border border-white/12 bg-white/10 p-5 shadow-lg shadow-primary-950/10 transition duration-300 hover:bg-white/15 motion-reduce:transition-none sm:backdrop-blur-md md:hover:-translate-y-1 md:motion-reduce:hover:translate-y-0">
    <div aria-hidden="true" class="absolute inset-x-0 top-0 h-px bg-white/20"></div>

    <div class="relative flex h-full flex-col justify-between gap-5">
        <div aria-hidden="true" class="flex h-10 w-10 items-center justify-center rounded-xl border border-white/12 bg-white/10 text-xs font-semibold text-white/80">{{ $index }}</div>

        <div>
            <div data-counter data-target="{{ $value }}" class="text-[2.4rem] font-bold tracking-tight text-white tabular-nums">{{ $value }}</div>
            <div class="mt-2 text-[15px] font-semibold leading-6 text-white">{{ $label }}</div>
            <div class="mt-1 text-[13px] leading-5 text-white/75">{{ $detail }}</div>
        </div>
    </div>
</div>
