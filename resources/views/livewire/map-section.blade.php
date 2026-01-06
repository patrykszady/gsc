<div>
    {{-- Map Section --}}
    {{-- Uses bg-fixed on desktop for parallax, static on mobile (bg-fixed doesn't work on mobile browsers) --}}
    <section class="relative mt-8 h-[250px] overflow-hidden sm:h-[300px] lg:h-[350px]">
        {{-- Background image: static on mobile, parallax (fixed) on desktop --}}
        <div
            class="absolute inset-0 bg-center bg-cover bg-scroll md:bg-fixed"
            style="background-image: url('{{ asset('images/gs_map.png') }}');"
        ></div>

        {{-- Subtle overlay for depth --}}
        <div class="absolute inset-0 bg-gradient-to-b from-transparent via-transparent to-slate-950/10 dark:to-slate-950/30"></div>
    </section>
</div>
