{{--
    "Cities and Suburbs We Commonly Serve" — the town chip row.

    Was inline on the home page only, built from a hardcoded list of 14
    "priority" slugs topped up by AreaServed::withProjectCounts(). Two problems
    that fixed:

      1. The priority list had to be hand-edited whenever the area list changed
         and had gone stale — it still named orland-park, removed from the area
         list. It also meant the chips were not actually "the areas we serve the
         most", which is what the heading claims.
      2. The "All 89+ Areas" link was a hardcoded number against an actual 70.

    Towns now come from AreaServed::orderedByLocalProjects() — the admin area
    list, ordered by how many published projects sit in each town — so the row
    matches its own heading and needs no maintenance. Same ordering the footer
    and the service pages use.

    Props
      limit       int    — how many chips to show.
      heading     string|null — omit for a bare chip row.
      subheading  string|null
      allLink     bool   — append the "All {n}+ Areas" chip.
      exclude     array  — area slugs to leave out (e.g. the town whose page
                           this is, so a Barrington page does not link to itself).
--}}

@props([
    'limit' => 18,
    'heading' => 'Cities and Suburbs We Commonly Serve Near Chicago',
    'subheading' => 'Local kitchen, bathroom, addition, mudroom, basement & home remodeling in the cities and suburbs we serve the most.',
    'allLink' => true,
    'exclude' => [],
])

@php
    $chipAreas = \App\Models\AreaServed::orderedByLocalProjects()
        ->reject(fn ($area) => in_array($area->slug, (array) $exclude, true))
        ->take((int) $limit);
@endphp

@if($chipAreas->isNotEmpty())
    <div {{ $attributes }}>
        @if($heading)
            <h2 class="text-center font-heading text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                {{ $heading }}
            </h2>
        @endif

        @if($subheading)
            <p class="mx-auto mt-2 max-w-2xl text-center text-sm text-gray-600 dark:text-gray-400">
                {{ $subheading }}
            </p>
        @endif

        <div @class(['mx-auto flex max-w-4xl flex-wrap justify-center gap-2', 'mt-6' => $heading || $subheading])>
            @foreach($chipAreas as $chipArea)
                <a
                    href="{{ $chipArea->url }}"
                    wire:navigate.hover
                    class="rounded-full border border-gray-200 bg-white px-4 py-1.5 text-sm font-medium text-gray-700 transition hover:border-sky-300 hover:text-sky-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:text-sky-400"
                >
                    {{ $chipArea->city }}
                </a>
            @endforeach

            @if($allLink)
                {{-- Count derived, never typed: this read "All 89+ Areas"
                     against an actual 70. --}}
                <a
                    href="/areas-served"
                    wire:navigate.hover
                    class="rounded-full border border-sky-200 bg-sky-50 px-4 py-1.5 text-sm font-semibold text-sky-700 transition hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300"
                >
                    All {{ \App\Support\CompanyStats::citiesServedLabel() }} Areas &rarr;
                </a>
            @endif
        </div>
    </div>
@endif
