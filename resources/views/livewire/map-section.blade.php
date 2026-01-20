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
                x-data="{
                    zipCounts: @js($zipCounts),
                    maxCount: {{ $maxCount }},
                    mapCenter: @js($mapCenter),
                    mapModuleLoaded: false,
                    isInteractive: false,
                    async init() {
                        if (this.mapModuleLoaded) return;
                        this.mapModuleLoaded = true;
                        const { createProjectZipMap } = await window.loadProjectZipMap();
                        Object.assign(this, createProjectZipMap(this.zipCounts, this.maxCount, this.mapCenter));
                        await this.initMap();
                    },
                    activateMap() {
                        this.isInteractive = true;
                        this.$dispatch('map-interaction', { active: true });
                        if (this.map) {
                            this.map.setOptions({
                                zoomControl: true,
                                gestureHandling: 'greedy',
                                draggable: true,
                                scrollwheel: true,
                                disableDoubleClickZoom: false,
                            });
                        }
                    },
                    deactivateMap() {
                        this.isInteractive = false;
                        this.$dispatch('map-interaction', { active: false });
                        if (this.map) {
                            this.map.setOptions({
                                zoomControl: false,
                                gestureHandling: 'none',
                                draggable: false,
                                scrollwheel: false,
                                disableDoubleClickZoom: true,
                            });
                        }
                    }
                }"
                x-intersect:enter.once="init()"
                class="absolute inset-0"
                wire:ignore
            >
                {{-- Fixed map container --}}
                <div 
                    class="fixed inset-0 h-screen w-full"
                    @mouseleave="deactivateMap()"
                >
                    <div class="size-full" x-ref="map"></div>
                </div>
                
                {{-- Click to interact overlay (inside clipped area so it scrolls correctly) --}}
                <div 
                    x-show="!isInteractive"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    @click="activateMap()"
                    class="absolute inset-0 z-10 cursor-pointer"
                >
                    <div class="absolute right-4 top-4 rounded-full bg-white/95 px-4 py-2.5 shadow-lg dark:bg-slate-800/95">
                        <div class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">
                            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 15l-2 5L9 9l11 4-5 2zm0 0l5 5M7.188 2.239l.777 2.897M5.136 7.965l-2.898-.777M13.95 4.05l-2.122 2.122m-5.657 5.656l-2.12 2.122" />
                            </svg>
                            <span>Click to explore map</span>
                        </div>
                    </div>
                </div>
            </div>
        @endif
        
        {{-- Subtle overlay for depth --}}
        <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-transparent via-transparent to-slate-950/10 dark:to-slate-950/30"></div>
    </section>

</div>
