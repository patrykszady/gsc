<div>
    {{-- Parallax Map Section --}}
    {{-- The image is fixed to the viewport, section acts as a window/mask --}}
    <section class="relative mt-8 h-[250px] overflow-hidden sm:h-[300px] lg:h-[350px]">
        {{-- Fixed background image --}}
        <div
            class="absolute inset-0 bg-fixed bg-cover bg-center"
            style="background-image: url('{{ asset('images/gs_map.png') }}');"
        ></div>

        {{-- Optional subtle overlay for depth --}}
        <div class="absolute inset-0 bg-gradient-to-b from-transparent via-transparent to-slate-950/10 dark:to-slate-950/30"></div>
    </section>
</div>
