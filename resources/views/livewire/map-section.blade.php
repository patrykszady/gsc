<div>
    {{-- Project Map Section - Uses clip-path to create bg-fixed effect with fixed map --}}
    <section class="relative h-[250px] sm:h-[300px] lg:h-[350px]" style="clip-path: inset(0);">
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

    @once
        <script>
            document.addEventListener('alpine:init', () => {
                Alpine.data('projectZipMap', (zipCounts, maxCount, mapCenter) => ({
                    map: null,
                    geocoder: null,
                    circles: [],
                    animationId: null,
                    initialized: false,
                    async init() {
                        if (this.initialized) return;
                        this.initialized = true;
                        
                        await this.waitForGoogleMaps();
                        if (!window.google?.maps?.importLibrary) return;

                        const { Map } = await google.maps.importLibrary('maps');
                        await google.maps.importLibrary('geocoding');

                        this.map = new Map(this.$refs.map, {
                            center: mapCenter,
                            zoom: 10,
                            mapTypeControl: false,
                            streetViewControl: false,
                            fullscreenControl: false,
                            zoomControl: false,
                            gestureHandling: 'greedy',
                            minZoom: 9,
                            maxZoom: 15,
                            styles: [
                                { elementType: 'geometry', stylers: [{ color: '#f4f6f9' }] },
                                { elementType: 'labels.text.fill', stylers: [{ color: '#4b5563' }] },
                                { elementType: 'labels.text.stroke', stylers: [{ color: '#f4f6f9' }] },
                                { featureType: 'administrative', elementType: 'geometry.stroke', stylers: [{ color: '#d1d5db' }] },
                                { featureType: 'poi', elementType: 'geometry', stylers: [{ color: '#eef2f7' }] },
                                { featureType: 'poi', elementType: 'labels.text.fill', stylers: [{ color: '#6b7280' }] },
                                { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#ffffff' }] },
                                { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: '#e5e7eb' }] },
                                { featureType: 'road', elementType: 'labels.text.fill', stylers: [{ color: '#6b7280' }] },
                                { featureType: 'water', elementType: 'geometry', stylers: [{ color: '#1e3a5f' }] },
                                { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#f4f6f9' }] },
                            ],
                        });

                        this.geocoder = new google.maps.Geocoder();
                        await this.renderZipCircles(zipCounts, Math.max(maxCount, 1));
                        this.startPulseAnimation();
                    },
                    startPulseAnimation() {
                        // Cancel any existing animation
                        if (this.animationId) {
                            cancelAnimationFrame(this.animationId);
                        }
                        
                        // Each circle pulses independently with its own phase offset
                        let time = 0;
                        const animate = () => {
                            time += 0.04; // Moderate speed
                            
                            this.circles.forEach((circle, i) => {
                                // Each circle has its own phase offset for independent pulsing
                                const phase = time + (i * 0.6);
                                const pulse = Math.sin(phase);
                                
                                // Radius scales between 92% and 108% of base
                                const scale = 1 + pulse * 0.08;
                                circle.setRadius(circle.baseRadius * scale);
                                
                                // Moderate opacity pulse
                                const opacity = 0.30 + pulse * 0.05;
                                circle.setOptions({ fillOpacity: opacity });
                            });
                            
                            this.animationId = requestAnimationFrame(animate);
                        };
                        this.animationId = requestAnimationFrame(animate);
                    },
                    async renderZipCircles(zipCounts, maxCountSafe) {
                        // Clear any existing circles first
                        this.circles.forEach(c => c.setMap(null));
                        this.circles = [];
                        
                        const points = await Promise.all(zipCounts.map(async (zipData) => {
                            const location = await this.geocodeZip(zipData.zip);
                            if (!location) return null;
                            return { ...zipData, location };
                        }));

                        const validPoints = points.filter(Boolean);

                        validPoints.forEach((point) => {
                            const intensity = Math.sqrt(point.count / maxCountSafe);
                            const baseRadius = 2000 + intensity * 8000;

                            const circle = new google.maps.Circle({
                                map: this.map,
                                center: point.location,
                                radius: baseRadius,
                                fillColor: '#0ea5e9',
                                fillOpacity: 0.3,
                                strokeColor: '#0284c7',
                                strokeOpacity: 0.8,
                                strokeWeight: 1,
                            });

                            circle.baseRadius = baseRadius;
                            this.circles.push(circle);
                        });

                        if (validPoints.length > 0) {
                            const avg = validPoints.reduce(
                                (acc, point) => ({
                                    lat: acc.lat + point.location.lat,
                                    lng: acc.lng + point.location.lng,
                                }),
                                { lat: 0, lng: 0 }
                            );
                            this.map.setCenter({
                                lat: avg.lat / validPoints.length,
                                lng: avg.lng / validPoints.length,
                            });
                            this.map.setZoom(11);
                        }
                    },
                    waitForGoogleMaps() {
                        return new Promise((resolve) => {
                            let attempts = 0;
                            const timer = setInterval(() => {
                                attempts += 1;
                                if (window.google?.maps?.importLibrary || attempts > 20) {
                                    clearInterval(timer);
                                    resolve();
                                }
                            }, 250);
                        });
                    },
                    async geocodeZip(zip) {
                        const cached = this.getCachedZip(zip);
                        if (cached) return cached;

                        return new Promise((resolve) => {
                            this.geocoder.geocode({ address: `${zip} USA` }, (results, status) => {
                                if (status === 'OK' && results[0]) {
                                    const loc = results[0].geometry.location;
                                    const coords = { lat: loc.lat(), lng: loc.lng() };
                                    this.setCachedZip(zip, coords);
                                    resolve(coords);
                                    return;
                                }
                                resolve(null);
                            });
                        });
                    },
                    getCachedZip(zip) {
                        try {
                            const cache = JSON.parse(localStorage.getItem('zipGeoCache') || '{}');
                            return cache[zip] || null;
                        } catch (e) {
                            return null;
                        }
                    },
                    setCachedZip(zip, coords) {
                        try {
                            const cache = JSON.parse(localStorage.getItem('zipGeoCache') || '{}');
                            cache[zip] = coords;
                            localStorage.setItem('zipGeoCache', JSON.stringify(cache));
                        } catch (e) {
                            // Ignore cache write failures
                        }
                    },
                }));
            });
        </script>
    @endonce
</div>
