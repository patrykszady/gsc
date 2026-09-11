/*
 * Branded Google Map — ONE base for every map the estate draws: the central
 * admin's areas map (ss-systems), the public areas map (jpeterson-design) and
 * the public project map (gs.construction). This file is byte-identical in
 * all three repos (resources/js/branded-map.js); change it in one, copy it to
 * the others.
 *
 * What it owns:
 *   - loading the Maps JavaScript API once (the importLibrary bootstrap),
 *   - the tint: land, roads and water drawn from a colour ramp the page
 *     already carries as CSS variables (`--color-sky-*` in the admin, which
 *     each site's accent remaps; `--color-brand-*` on jpeterson's public
 *     pages; Tailwind's sky on gs.construction's),
 *   - the two pin shapes: a filled dot for a town, a hollow ring for a
 *     market, sized so a town at the very same spot still shows through,
 *   - the map options every one of them shares: no street view or map-type
 *     controls, a zoom floor and a bounds restriction that keep the map on
 *     the country the business works in.
 *
 * Google Maps only accepts hex colours in its stylers, and Tailwind v4
 * writes its stock ramp in oklch(); every colour read here is normalised to
 * #rrggbb through a canvas first, so a ramp in any CSS colour syntax works.
 *
 * Exposed as window.BrandedMap for the inline scripts Blade views carry; they
 * wait on BrandedMap.ready() because Vite's bundle is a deferred module that
 * runs after inline scripts.
 */

const HEX = /^#[0-9a-f]{6}$/i;

export function toHex(value, fallback) {
    if (! value) return fallback;
    if (HEX.test(value)) return value;
    try {
        const ctx = document.createElement('canvas').getContext('2d');
        ctx.fillStyle = '#000000';
        ctx.fillStyle = value;
        return HEX.test(ctx.fillStyle) ? ctx.fillStyle : fallback;
    } catch (e) {
        return fallback;
    }
}

/** A CSS variable from :root as a hex colour, or the fallback. */
export function cssColor(name, fallback) {
    return toHex(getComputedStyle(document.documentElement).getPropertyValue(name).trim(), fallback);
}

/** Tailwind's stock sky ramp — the fallback when a variable is missing. */
export const SKY = {
    50: '#f0f9ff', 100: '#e0f2fe', 200: '#bae6fd', 300: '#7dd3fc', 400: '#38bdf8',
    500: '#0ea5e9', 600: '#0284c7', 700: '#0369a1', 800: '#075985', 900: '#0c4a6e',
};

/** The ramp under `prefix` (e.g. '--color-sky' or '--color-brand') as hex, step by step. */
export function ramp(prefix, fallbacks = SKY) {
    const out = {};
    for (const step of Object.keys(fallbacks)) {
        out[step] = cssColor(`${prefix}-${step}`, fallbacks[step]);
    }
    return out;
}

/**
 * The tint: land a faint tint of the ramp, parks a shade deeper, roads
 * white with a tinted edge, water and the loading background the dark
 * end, points of interest and transit hidden so the pins stay legible.
 */
export function styles(colors) {
    return [
        { elementType: 'geometry', stylers: [{ color: colors[50] }] },
        { elementType: 'labels.text.fill', stylers: [{ color: '#3f3f46' }] },
        { elementType: 'labels.text.stroke', stylers: [{ color: '#ffffff' }] },
        { featureType: 'administrative', elementType: 'geometry.stroke', stylers: [{ color: colors[300] }] },
        { featureType: 'landscape.natural', elementType: 'geometry', stylers: [{ color: colors[50] }] },
        { featureType: 'poi', stylers: [{ visibility: 'off' }] },
        { featureType: 'poi.park', elementType: 'geometry', stylers: [{ color: colors[100] }] },
        { featureType: 'road', elementType: 'geometry', stylers: [{ color: '#ffffff' }] },
        { featureType: 'road', elementType: 'geometry.stroke', stylers: [{ color: colors[200] }] },
        { featureType: 'road.highway', elementType: 'geometry', stylers: [{ color: colors[200] }] },
        { featureType: 'road.highway', elementType: 'geometry.stroke', stylers: [{ color: colors[300] }] },
        { featureType: 'transit', stylers: [{ visibility: 'off' }] },
        { featureType: 'water', elementType: 'geometry', stylers: [{ color: colors[700] }] },
        { featureType: 'water', elementType: 'labels.text.fill', stylers: [{ color: '#ffffff' }] },
        { featureType: 'water', elementType: 'labels.text.stroke', stylers: [{ color: colors[700] }] },
    ];
}

/** The contiguous United States, with a little sea around it. */
export const US_BOUNDS = { north: 50, south: 24, west: -126, east: -66 };

/** Map options every branded map shares; pass `center`, `zoom` and any overrides. */
export function options(colors, overrides = {}) {
    return {
        streetViewControl: false,
        mapTypeControl: false,
        styles: styles(colors),
        backgroundColor: colors[700],
        // Stay on the country the business works in: no zooming out to
        // the world, no panning off to another continent.
        minZoom: 4,
        restriction: { latLngBounds: US_BOUNDS, strictBounds: false },
        ...overrides,
    };
}

/** A town: a filled dot with a white edge. */
export function dot(color, scale = 7) {
    return {
        path: google.maps.SymbolPath.CIRCLE,
        scale,
        fillColor: color,
        fillOpacity: 0.9,
        strokeColor: '#ffffff',
        strokeWeight: 1.5,
    };
}

/**
 * A market: a hollow ring, larger than any town dot, so a town at the very
 * same spot still shows through it. A faint fill, not none, so the whole
 * disc takes a click. The market's name sits above the ring (labelOrigin
 * is in path units, scaled like the circle).
 */
export function ring(color, scale = 15) {
    return {
        path: google.maps.SymbolPath.CIRCLE,
        scale,
        fillColor: color,
        fillOpacity: 0.12,
        strokeColor: color,
        strokeWeight: 3,
        labelOrigin: new google.maps.Point(0, -1.9),
    };
}

/** The label a market ring carries. */
export function ringLabel(text) {
    return { text, color: '#1c1917', fontSize: '12px', fontWeight: '600' };
}

let loading = null;

/**
 * Load the Maps JavaScript API once (Google's importLibrary bootstrap) and
 * resolve with the maps + marker libraries. Safe to call from every map on
 * the page; the second call shares the first load.
 */
export function load(key, libraries = ['maps', 'marker']) {
    if (! loading) {
        loading = (async () => {
            if (! window.google?.maps?.importLibrary) {
                /* eslint-disable */
                (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({ key, v: 'weekly' });
                /* eslint-enable */
            }
            const loaded = {};
            for (const name of libraries) {
                loaded[name] = await google.maps.importLibrary(name);
            }
            return loaded;
        })();
    }
    return loading;
}

/**
 * The whole thing in one call: load the API, read the ramp under `prefix`,
 * make the map in `el` with the shared options, and hand back what a page
 * needs to add its own pins and popups.
 */
export async function create(el, { key, prefix = '--color-sky', fallbacks = SKY, center, zoom = 5, ...overrides } = {}) {
    const libs = await load(key);
    const colors = ramp(prefix, fallbacks);
    const map = new libs.maps.Map(el, options(colors, { center, zoom, ...overrides }));
    return {
        map,
        colors,
        accent: colors[500],
        libs,
        dot: (scale) => dot(colors[500], scale),
        ring: (scale) => ring(colors[500], scale),
        ringLabel,
    };
}

const api = { toHex, cssColor, SKY, ramp, styles, US_BOUNDS, options, dot, ring, ringLabel, load, create };

/** Resolves with the API once the bundle has run — for inline scripts that may run first. */
api.ready = () => Promise.resolve(api);

if (typeof window !== 'undefined') {
    window.BrandedMap = api;
    document.dispatchEvent(new CustomEvent('brandedmap:ready'));
}

export default api;
