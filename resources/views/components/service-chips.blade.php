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
      area     AreaServed (required)
      exclude  string|null — omit one slug, for a page that IS that service.
      labels   array — per-slug anchor text overrides, keyed by slug. The area
                       page varies "Kitchen Remodeling" to "Kitchen Remodeling
                       Contractor" deliberately, so this stays configurable
                       rather than being flattened to one wording.
--}}

@props([
    'area',
    'exclude' => null,
    'labels' => [],
])

@php
    $serviceChips = array_merge([
        'kitchen-remodeling'  => 'Kitchen Remodeling',
        'bathroom-remodeling' => 'Bathroom Remodeling',
        'home-remodeling'     => 'Home Remodeling',
        'basement-remodeling' => 'Basement Remodeling',
        'home-additions'      => 'Home Additions',
    ], $labels);
@endphp

<div {{ $attributes->class('grid grid-cols-2 gap-3 sm:flex sm:flex-wrap') }}>
    @foreach($serviceChips as $slug => $label)
        @continue($slug === $exclude)

        <a
            href="{{ $area->serviceUrl($slug) }}"
            wire:navigate
            class="rounded-lg bg-white px-4 py-2 text-center text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-sky-50 hover:text-sky-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-700"
        >
            {{ $area->city }} {{ $label }}
        </a>
    @endforeach
</div>
