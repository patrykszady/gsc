{{--
    The city → service chip row.

    Was written three times: partials/area-service-links, the area page's
    "Remodeling Services Homeowners Search for in {city}" block (five hardcoded
    <a> tags) and its "More Remodeling Services in {city}" block. Same five
    links, three different hover states, two different dark backgrounds.

    All three also wrapped one chip per row on a phone: a "{City} Bathroom
    Remodeling" chip measures ~233px and a 390px viewport leaves 358px, so no
    two ever shared a line. Two columns below sm fixes that — the chips split
    the row and the label wraps INSIDE the chip instead of the row wrapping.
    From sm up they return to content-width flex-wrap.

    Props
      area     AreaServed|null — when set, chips link to that town's service
                       pages and are prefixed with the city. When null the chips
                       link to the site-wide /services/* pages instead, which is
                       what the main service pages need. Passing an area is the
                       only difference between the two modes.
      exclude  string|null — omit one slug, for a page that IS that service.
      labels   array — per-slug anchor text overrides, keyed by slug. The area
                       page varies "Kitchen Remodeling" to "Kitchen Remodeling
                       Contractor" deliberately, so this stays configurable
                       rather than being flattened to one wording.
--}}

@props([
    'area' => null,
    'exclude' => null,
    'labels' => [],
])

@php
    // One catalog, shared with the footer, the projects grid and the service
    // pages — adding a service adds its chip everywhere at once.
    $serviceChips = \App\Support\ServiceCatalog::all()
        ->mapWithKeys(fn (array $s): array => [$s['slug'] => $s['label']])
        ->all();

    // Area pages have no mudroom spoke, so a city-prefixed mudroom chip would
    // 404. Site-wide mode links to /services/* where the page does exist.
    if ($area) {
        unset($serviceChips['mudroom-remodeling']);
    }

    $serviceChips = array_merge($serviceChips, $labels);
    $serviceUrls = \App\Support\ServiceCatalog::all()->pluck('url', 'slug');
@endphp

<div {{ $attributes->class('grid grid-cols-2 gap-3 sm:flex sm:flex-wrap') }}>
    @foreach($serviceChips as $slug => $label)
        @continue($slug === $exclude)
        @continue(! $area && ! $serviceUrls->has($slug))

        <a
            href="{{ $area ? $area->serviceUrl($slug) : $serviceUrls[$slug] }}"
            wire:navigate
            class="rounded-lg bg-white px-4 py-2 text-center text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-sky-50 hover:text-sky-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-700"
        >
            {{ $area ? $area->city . ' ' . $label : $label }}
        </a>
    @endforeach
</div>
