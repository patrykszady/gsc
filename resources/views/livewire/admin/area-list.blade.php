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

    {{-- Service-area map: markers for every area; click an empty spot to add
         the town under it, click a marker to edit/remove. --}}
    <flux:card class="mb-6">
        <div class="mb-3 flex items-center justify-between">
            <flux:heading size="md">Coverage map</flux:heading>
            <flux:text class="text-xs text-zinc-500">
                Click an empty spot to add that town · click a dot to edit or remove ·
                <span class="inline-block h-2 w-2 rounded-full bg-sky-500"></span> has content
                <span class="ml-2 inline-block h-2 w-2 rounded-full bg-amber-500"></span> empty
            </flux:text>
        </div>
        <div wire:ignore id="areas-admin-map" class="h-[480px] w-full rounded-lg bg-zinc-100 dark:bg-zinc-800"></div>
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

            let map, geocoder, infoWindow;
            let shapes = [];

            const openAreaPopup = (area, position, anchor) => {
                const el = document.createElement('div');
                el.style.cssText = 'font: 13px system-ui; color: #18181b; min-width: 180px';
                el.innerHTML = `
                    <strong style="font-size:14px">${area.city}</strong>
                    <div style="color:#71717a">/${area.slug} · ${area.hasContent ? 'has content' : 'no content yet'}</div>
                    <div style="margin-top:8px; display:flex; gap:6px">
                        <a href="${area.editUrl}" style="padding:4px 10px; background:#0ea5e9; color:#fff; border-radius:6px; text-decoration:none">Edit</a>
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

            const drawAreas = (areas) => {
                shapes.forEach(s => s.setMap(null));
                shapes = areas.map(area => {
                    const marker = new google.maps.Marker({
                        map,
                        position: { lat: area.lat, lng: area.lng },
                        title: area.city,
                        icon: {
                            path: google.maps.SymbolPath.CIRCLE,
                            scale: 7,
                            fillColor: area.hasContent ? '#0ea5e9' : '#f59e0b',
                            fillOpacity: 0.9,
                            strokeColor: '#ffffff',
                            strokeWeight: 1.5,
                        },
                    });
                    marker.addListener('click', () => openAreaPopup(area, null, marker));
                    return marker;
                });
            };

            const init = async () => {
                const { Map, InfoWindow } = await google.maps.importLibrary('maps');
                await google.maps.importLibrary('marker');
                const { Geocoder } = await google.maps.importLibrary('geocoding');

                map = new Map(document.getElementById('areas-admin-map'), {
                    center: { lat: 42.10, lng: -87.98 },
                    zoom: 10,
                    streetViewControl: false,
                    mapTypeControl: false,
                });
                geocoder = new Geocoder();
                infoWindow = new InfoWindow();

                drawAreas(@js($this->mapAreas()));

                // Click an empty spot → reverse-geocode the town → confirm → add.
                map.addListener('click', async (e) => {
                    infoWindow.close();
                    const lat = e.latLng.lat(), lng = e.latLng.lng();
                    try {
                        const { results } = await geocoder.geocode({ location: e.latLng });
                        const withLocality = results.find(r => r.address_components.some(c => c.types.includes('locality')));
                        const comps = (withLocality ?? results[0])?.address_components ?? [];
                        const city = comps.find(c => c.types.includes('locality'))?.long_name;
                        const state = comps.find(c => c.types.includes('administrative_area_level_1'))?.short_name;
                        if (!city) { alert('No town found at that point — try clicking closer to its center.'); return; }
                        if (state !== 'IL') { alert(`${city} is in ${state} — outside the Illinois service area. Use the New Area form if this is intentional.`); return; }
                        if (confirm(`Add ${city}, IL as a service area?`)) {
                            $wire.createFromMap(city, lat, lng);
                        }
                    } catch (err) {
                        alert('Could not look up that location: ' + (err?.message ?? err));
                    }
                });
            };

            $wire.on('areas-map-updated', ({ areas }) => {
                if (map) drawAreas(areas);
            });

            init();
        })();
    </script>
    @endscript
</div>
