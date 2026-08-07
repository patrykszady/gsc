@props([
    // grid | floorplan | mesh | aurora — see the page-decor component.
    // (Never write a component tag in angle brackets here: Blade compiles
    // x-* tags even inside a PHP comment, which corrupts this array.)
    'variant' => 'grid',
])

@php
    // ?bg=grid|floorplan|mesh|aurora previews the other variants locally.
    $decor = app()->environment('local') ? request()->query('bg', $variant) : $variant;
@endphp

{{--
    The decorated page shell: tinted canvas + the page-decor background + your
    content. Wrap a whole page in it; extra classes merge onto the root, e.g.
    class="pb-4 lg:pb-0" for a page with a sticky mobile CTA bar to clear.

    Both /compare and /compare/* hand-rolled this — the same php block picking
    the variant, the same `relative isolate overflow-x-clip` root (which
    page-decor REQUIRES; every layer is absolutely positioned against it and
    -z-10 relies on the isolation) and the same decor call. The two copies had
    already drifted apart in their padding.

    NOTE: never write a component tag in angle brackets in this file, comments
    included. Blade compiles x-* tags and directives before it strips comments,
    and an example usage written inside the props array above compiled into a
    component call mid-array and took both pages down with a parse error.
--}}
<div {{ $attributes->merge(['class' => 'relative isolate overflow-x-clip bg-white dark:bg-zinc-950']) }}>
    <x-page-decor :variant="$decor" />

    {{ $slot }}
</div>
