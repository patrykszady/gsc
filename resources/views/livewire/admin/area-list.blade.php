<div>
    <div class="flex items-center justify-between mb-6">
        <div>
            <flux:heading size="xl">Service Areas</flux:heading>
            <flux:subheading>Cities/neighborhoods we serve. Each area can have custom intro content and SEO overrides.</flux:subheading>
        </div>
        <flux:button :href="route('admin.areas.create')" icon="plus" variant="primary">
            New Area
        </flux:button>
    </div>

    @if($mapFlash)
        <flux:callout variant="secondary" class="mb-4">{{ $mapFlash }}</flux:callout>
    @endif

    {{-- Service-area map. Every named town in view that is NOT already a
         service area shows as an orange candidate dot
         (from OpenStreetMap) — click one to add it. Existing areas draw in
         the tenant accent (filled = has content, grey = empty). Clicking an
         empty spot still works: the town is resolved server-side. --}}
    <flux:card class="mb-6">
        <div class="mb-3 flex items-center justify-between gap-4">
            <flux:heading size="md">Coverage map</flux:heading>
            <flux:text class="text-xs text-zinc-500">
                <span class="inline-block h-2 w-2 rounded-full bg-orange-500"></span> not in service area — click to add
                <span class="ml-2 inline-block h-2 w-2 rounded-full bg-sky-500"></span> has content
                <span class="ml-2 inline-block h-2 w-2 rounded-full bg-zinc-400"></span> no content yet
                <span id="areas-map-hint" class="ml-2 hidden text-amber-600">zoom in to see towns</span>
            </flux:text>
        </div>
        {{-- Jump buttons for a multi-market tenant. Fitting all of J. Peterson's
             markets at once spans 8.6° of latitude, which is wider than the
             candidate layer will load for — so the map opens on ONE market and
             these move between them. A single-metro site renders no buttons. --}}
        @if (count($markets = $this->mapMarkets()) > 1)
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <flux:text class="text-xs text-zinc-500">Jump to:</flux:text>
                @foreach ($markets as $m)
                    <button type="button"
                            data-market-jump
                            data-lat="{{ $m['lat'] }}"
                            data-lng="{{ $m['lng'] }}"
                            class="rounded-full border border-zinc-300 px-3 py-1 text-xs text-zinc-700 transition hover:border-sky-500 hover:text-sky-700 dark:border-zinc-600 dark:text-zinc-300 dark:hover:text-sky-400">
                        {{ $m['label'] }}
                    </button>
                @endforeach
            </div>
        @endif

        <div wire:ignore id="areas-admin-map" class="h-120 w-full rounded-lg bg-zinc-100 dark:bg-zinc-800"></div>
    </flux:card>

    <flux:card>
        <div class="mb-4">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                        placeholder="Search by city or slug…"/>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>City</flux:table.column>
                <flux:table.column>Slug</flux:table.column>
                <flux:table.column>Coordinates</flux:table.column>
                <flux:table.column>Has intro?</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse($areas as $area)
                    <flux:table.row :key="'area-'.$area->id">
                        <flux:table.cell class="font-medium">{{ $area->city }}</flux:table.cell>
                        <flux:table.cell class="text-zinc-500">{{ $area->slug }}</flux:table.cell>
                        <flux:table.cell class="text-xs text-zinc-500">
                            @if($area->latitude && $area->longitude)
                                {{ number_format($area->latitude, 4) }}, {{ number_format($area->longitude, 4) }}
                            @else
                                —
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            @if($area->hasUniqueContent())
                                <flux:badge color="emerald" size="sm">Yes</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">No</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell class="text-right">
                            <flux:button size="xs" variant="ghost"
                                         :href="route('admin.areas.edit', $area)">Edit</flux:button>
                            <flux:button size="xs" variant="danger"
                                         wire:click="delete({{ $area->id }})"
                                         wire:confirm="Delete {{ $area->city }}? This cannot be undone.">
                                Delete
                            </flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-500 py-6">
                            No areas yet.
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-4">{{ $areas->links() }}</div>
    </flux:card>

    @script
    <script>
        (() => {
            // Google Maps bootstrap (importLibrary pattern) — admin layout
            // doesn't load Maps, so this page brings its own.
            if (!window.google?.maps?.importLibrary) {
                (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.googleapis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({key: @js(config('services.google.places_api_key')), v: "weekly"});
            }

            let map, infoWindow;
            let shapes = [];
            let candidateShapes = [];

            // Dots follow the tenant's admin accent (the sky ramp is remapped
            // per site), so jpeterson's map is teal without touching this file.
            const cssVar = (name, fallback) =>
                getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
            const ACCENT = cssVar('--color-sky-500', '#0ea5e9');

            const dot = (color, scale) => ({
                path: google.maps.SymbolPath.CIRCLE,
                scale,
                fillColor: color,
                fillOpacity: 0.9,
                strokeColor: '#ffffff',
                strokeWeight: 1.5,
            });

            const openAreaPopup = (area, position, anchor) => {
                const el = document.createElement('div');
                el.style.cssText = 'font: 13px system-ui; color: #18181b; min-width: 180px';
                el.innerHTML = `
                    <strong style="font-size:14px">${area.city}</strong>
                    <div style="color:#71717a">/${area.slug} · ${area.hasContent ? 'has content' : 'no content yet'}</div>
                    <div style="margin-top:8px; display:flex; gap:6px">
                        <a href="${area.editUrl}" style="padding:4px 10px; background:var(--color-sky-500, #0ea5e9); color:#fff; border-radius:6px; text-decoration:none">Edit</a>
                        <a href="${area.publicUrl}" target="_blank" rel="noopener" style="padding:4px 10px; background:#f4f4f5; border-radius:6px; text-decoration:none; color:#18181b">View</a>
                        <button type="button" data-remove style="padding:4px 10px; background:#fee2e2; color:#b91c1c; border:0; border-radius:6px; cursor:pointer">Remove</button>
                    </div>`;
                el.querySelector('[data-remove]').addEventListener('click', () => {
                    if (confirm(`Remove ${area.city} from service areas? This deletes its page. This cannot be undone.`)) {
                        infoWindow.close();
                        $wire.call('delete', area.id);
                    }
                });
                infoWindow.setContent(el);
                if (anchor) {
                    infoWindow.open({ map, anchor });
                } else {
                    infoWindow.setPosition(position);
                    infoWindow.open({ map });
                }
            };

            // Same InfoWindow treatment as an existing area — clicking any dot
            // opens a tooltip in the map, never a browser confirm() box.
            const openCandidatePopup = (town, anchor, position) => {
                const el = document.createElement('div');
                el.style.cssText = 'font: 13px system-ui; color: #18181b; min-width: 200px';
                el.innerHTML = `
                    <strong style="font-size:14px">${town.name}</strong>
                    <div style="color:#71717a">Not a service area yet</div>
                    <div style="color:#71717a; margin-top:4px; font-size:12px">
                        Landmarks and permit notes are reused if another site already has
                        them; this site's own intro copy is generated on add.
                    </div>
                    <div style="margin-top:8px; display:flex; gap:6px">
                        <button type="button" data-add style="padding:4px 10px; background:var(--color-sky-600, #0284c7); color:#fff; border:0; border-radius:6px; cursor:pointer">Add to service area</button>
                        <button type="button" data-cancel style="padding:4px 10px; background:#f4f4f5; border:0; border-radius:6px; cursor:pointer; color:#18181b">Cancel</button>
                    </div>`;

                el.querySelector('[data-add]').addEventListener('click', (e) => {
                    e.target.disabled = true;
                    e.target.textContent = 'Adding…';
                    infoWindow.close();
                    $wire.createFromMap(town.name, town.lat, town.lng);
                });
                el.querySelector('[data-cancel]').addEventListener('click', () => infoWindow.close());

                infoWindow.setContent(el);
                if (anchor) {
                    infoWindow.open({ map, anchor });
                } else {
                    infoWindow.setPosition(position);
                    infoWindow.open({ map });
                }
            };

            // Plain message in the map, replacing alert() on the add path.
            const openNoticePopup = (title, body, position) => {
                const el = document.createElement('div');
                el.style.cssText = 'font: 13px system-ui; color: #18181b; max-width: 260px';
                el.innerHTML = `<strong style="font-size:14px"></strong><div style="color:#71717a; margin-top:4px"></div>`;
                el.querySelector('strong').textContent = title;
                el.querySelector('div').textContent = body;
                infoWindow.setContent(el);
                infoWindow.setPosition(position);
                infoWindow.open({ map });
            };

            const drawAreas = (areas) => {
                shapes.forEach(s => s.setMap(null));
                shapes = areas.map(area => {
                    const marker = new google.maps.Marker({
                        map,
                        position: { lat: area.lat, lng: area.lng },
                        title: area.city,
                        zIndex: 2,
                        icon: dot(area.hasContent ? ACCENT : '#a1a1aa', 7),
                    });
                    marker.addListener('click', () => openAreaPopup(area, null, marker));
                    return marker;
                });
            };

            // ---- Candidate towns: every named town in view, from OSM. ----
            // Orange, one click to add. The name and coordinates travel with
            // the dot, so no geocoding happens at all on this path.
            const drawCandidates = (towns) => {
                candidateShapes.forEach(s => s.setMap(null));
                candidateShapes = towns.map(t => {
                    const marker = new google.maps.Marker({
                        map,
                        position: { lat: t.lat, lng: t.lng },
                        title: `${t.name} — click to add`,
                        zIndex: 1,
                        icon: dot('#f97316', 6),
                    });
                    marker.addListener('click', () => openCandidatePopup(t, marker));
                    return marker;
                });
            };

            let candidateTimer, candidateSeq = 0;
            const refreshCandidates = () => {
                clearTimeout(candidateTimer);
                candidateTimer = setTimeout(async () => {
                    const b = map.getBounds();
                    if (!b) return;
                    const seq = ++candidateSeq;
                    const sw = b.getSouthWest(), ne = b.getNorthEast();
                    // Livewire methods return their value to the caller.
                    const res = await $wire.candidates(sw.lat(), sw.lng(), ne.lat(), ne.lng());
                    if (seq !== candidateSeq) return; // a newer viewport superseded this one

                    // Say WHY there are no dots. "Too wide" and "OpenStreetMap
                    // didn't answer" look identical on the map otherwise, and
                    // Overpass throttles often enough to matter.
                    // Reads the local gazetteer, so there is no failure mode
                    // to retry around any more — only "too wide to draw" and
                    // "this region has not been imported yet".
                    const hint = document.getElementById('areas-map-hint');
                    if (hint) {
                        hint.textContent = res.tooWide
                            ? 'zoom in to see towns'
                            : (res.needsImport ? 'no towns cached here — run: php artisan towns:import' : '');
                        hint.classList.toggle('hidden', !res.tooWide && !res.needsImport);
                    }

                    drawCandidates(res.towns);
                }, 250);
            };

            const init = async () => {
                const { Map, InfoWindow } = await google.maps.importLibrary('maps');
                await google.maps.importLibrary('marker');

                map = new Map(document.getElementById('areas-admin-map'), {
                    center: { lat: 42.10, lng: -87.98 },
                    zoom: 10,
                    streetViewControl: false,
                    mapTypeControl: false,
                });
                infoWindow = new InfoWindow();

                // Open framing this tenant's geography: its areas, or its
                // declared markets when it has no areas yet. jpeterson's map
                // must not open on Chicagoland while Jill is in Atlanta.
                // Frame this tenant's geography — but never wider than the
                // candidate layer will load for. J. Peterson's three markets
                // span 8.6° of latitude; fitting all of them would open the
                // map already past the "zoom in to see towns" threshold, so
                // the orange dots would be missing on arrival. When the fit is
                // that wide we open on the FIRST point (the home market) and
                // the jump buttons move between them.
                const MAX_FIT_DEG = 4.5; // stays inside the server's 5° guard
                const fit = @js($this->initialFit());

                if (fit.length === 1) {
                    map.setCenter({ lat: fit[0].lat, lng: fit[0].lng });
                } else if (fit.length > 1) {
                    const lats = fit.map(p => p.lat), lngs = fit.map(p => p.lng);
                    const spanLat = Math.max(...lats) - Math.min(...lats);
                    const spanLng = Math.max(...lngs) - Math.min(...lngs);

                    if (spanLat > MAX_FIT_DEG || spanLng > MAX_FIT_DEG) {
                        map.setCenter({ lat: fit[0].lat, lng: fit[0].lng });
                        map.setZoom(10);
                    } else {
                        const bounds = new google.maps.LatLngBounds();
                        fit.forEach(p => bounds.extend(p));
                        map.fitBounds(bounds, 48);
                    }
                }

                // Jump buttons (multi-market tenants only).
                document.querySelectorAll('[data-market-jump]').forEach(btn => {
                    btn.addEventListener('click', () => {
                        map.setCenter({ lat: +btn.dataset.lat, lng: +btn.dataset.lng });
                        map.setZoom(10);
                    });
                });

                drawAreas(@js($this->mapAreas()));

                // Candidates load on every settle: initial view, pan, zoom.
                map.addListener('idle', refreshCandidates);

                // Click an empty spot → server-side town lookup → same tooltip.
                // (Nominatim via Livewire — the browser Google Geocoder was
                // referer-blocked on *.localhost and preview hosts.)
                // Adding a town opens an InfoWindow whether you clicked its dot
                // or bare map; nothing on the add path uses a browser dialog.
                map.addListener('click', async (e) => {
                    infoWindow.close();
                    const lat = e.latLng.lat(), lng = e.latLng.lng();
                    try {
                        const info = await $wire.resolveTown(lat, lng);
                        const at = e.latLng;

                        if (!info.city) {
                            openNoticePopup('No town found here', 'Try clicking closer to a town centre, or use an orange dot.', at);
                            return;
                        }
                        if (!info.allowed) {
                            openNoticePopup(
                                `${info.city}, ${info.state}`,
                                `Outside this site's service states (${info.allowedStates.join(', ')}). Use the New Area form if this is intentional.`,
                                at,
                            );
                            return;
                        }

                        openCandidatePopup({ name: info.city, lat, lng }, null, at);
                    } catch (err) {
                        openNoticePopup('Lookup failed', String(err?.message ?? err), e.latLng);
                    }
                });
            };

            $wire.on('areas-map-updated', ({ areas }) => {
                if (map) {
                    drawAreas(areas);
                    refreshCandidates(); // the added town must drop off the candidate layer
                }
            });

            init();
        })();
    </script>
    @endscript
</div>
