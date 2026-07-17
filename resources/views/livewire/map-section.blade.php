<div>
    {{-- Crawlable proof line above the JS-only bubble map (area pages only). --}}
    @if(!empty($proofStats) && $proofStats['nearby'] > 0 && $area)
        <div class="mx-auto max-w-7xl px-4 pb-4 sm:px-6 lg:px-8">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                Completed projects around {{ $area->city }}
            </h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                GS Construction crews have completed
                <strong class="font-semibold text-zinc-900 dark:text-white">{{ number_format($proofStats['nearby']) }} {{ \Illuminate\Support\Str::plural('project', $proofStats['nearby']) }} within {{ $proofStats['radius'] }} miles of {{ $area->city }}</strong>
                — part of {{ number_format($proofStats['total']) }} completed projects across
                {{ $proofStats['zips'] }} Chicago-area ZIP codes. The map below shows real
                project density from our records, not a service-area claim.
            </p>
        </div>
    @endif
    {{-- Project Map Section - Uses clip-path to create bg-fixed effect with fixed map --}}
    <section class="relative {{ $heightClasses }}" style="clip-path: inset(0);">
        @if(!config('services.google.places_api_key'))
            <div
                class="absolute inset-0 bg-fixed bg-center bg-cover"
                style="background-image: url('{{ asset('images/gs_map.png') }}');"
            ></div>
        @elseif($zipPoints->isEmpty())
            <div class="flex size-full items-center justify-center bg-white dark:bg-zinc-800">
                <div class="text-center">
                    <p class="text-base font-semibold text-zinc-700 dark:text-zinc-200">No Hive zip data yet.</p>
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Run the Hive sync to populate map bubbles.</p>
                </div>
            </div>
        @else
            {{-- Fixed map - clip-path on parent creates the mask effect --}}
            <div
                x-data="{
                    zipPoints: @js($zipPoints),
                    maxCount: {{ $maxCount }},
                    mapCenter: @js($mapCenter),
                    mapModuleLoaded: false,
                    isInteractive: false,
                    async loadMap() {
                        if (this.mapModuleLoaded) return;
                        this.mapModuleLoaded = true;
                        try {
                            // Guard bots/adblock/content filters where app.js never exposes
                            // window.loadProjectZipMap; skip gracefully instead of throwing.
                            if (typeof window.loadProjectZipMap !== 'function') {
                                this.mapModuleLoaded = false;
                                return;
                            }

                            const { createProjectZipMap } = await window.loadProjectZipMap();
                            Object.assign(this, createProjectZipMap(this.zipPoints, this.maxCount, this.mapCenter));
                            await this.initMap();

                            // Kick tile rendering on multiple frames — the clip-path container
                            // can confuse Google Maps' visibility detection.
                            if (this.map) {
                                const kick = () => {
                                    google.maps.event.trigger(this.map, 'resize');
                                    this.map.setCenter(this.mapCenter);
                                };
                                requestAnimationFrame(kick);
                                setTimeout(kick, 300);
                                setTimeout(kick, 1000);
                            }
                        } catch (_) {
                            this.mapModuleLoaded = false;
                        }
                    },
                    activateMap() {
                        this.isInteractive = true;
                        if (this.map) {
                            this.map.setOptions({
                                zoomControl: true,
                                gestureHandling: 'greedy',
                            });
                        }
                    },
                    deactivateMap() {
                        this.isInteractive = false;
                        if (this.map) {
                            this.map.setOptions({
                                zoomControl: false,
                                gestureHandling: 'cooperative',
                            });
                        }
                    }
                }"
                x-intersect.margin.500px:enter.once="loadMap()"
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
