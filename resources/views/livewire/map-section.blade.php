<div>
    {{-- Project Map Section - Uses clip-path to create bg-fixed effect with fixed map --}}
    <section class="relative {{ $heightClasses }}" style="clip-path: inset(0);">
        @if(!config('services.google.places_api_key'))
            <div
                class="absolute inset-0 bg-fixed bg-center bg-cover"
                style="background-image: url('{{ asset('images/gs_map.png') }}');"
            ></div>
        @elseif($zipCounts->isEmpty())
            <div class="flex size-full items-center justify-center bg-white dark:bg-zinc-800">
                <div class="text-center">
                    <p class="text-base font-semibold text-zinc-700 dark:text-zinc-200">No zip code data yet.</p>
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Add zip codes to projects to populate the map.</p>
                </div>
            </div>
        @else
            {{-- Fixed map - clip-path on parent creates the mask effect --}}
            <div
                x-data="projectZipMap(@js($zipCounts), {{ $maxCount }}, @js($mapCenter))"
                x-init="init()"
                class="fixed inset-0 h-screen w-full"
                wire:ignore
            >
                <div class="size-full" x-ref="map"></div>
            </div>
        @endif
        
        {{-- Subtle overlay for depth --}}
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-transparent via-transparent to-slate-950/10 dark:to-slate-950/30"></div>
    </section>

</div>
