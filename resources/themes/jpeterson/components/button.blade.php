{{--
    J. Peterson Design — button.

    Theme-scoped: it resolves as <x-button> only while the jpeterson theme
    overlay is on the view finder, exactly like <x-layouts.app> in this same
    directory. gs.construction has its own <x-buttons.cta> and is untouched.

    ---- Colour is load-bearing here, do not "brighten" it ----
    --color-brand-500 (#4e9da2) is the logo teal, but WHITE ON IT IS 3.15:1 —
    below the WCAG AA floor of 4.5:1 for a label this size. --color-brand-600
    (#408085) is tuned to be the brightest teal at the same hue and saturation
    that clears it, at 4.52:1. That is why the filled button is -600 and not
    the logo colour itself. Measured ratios, all AA:

        primary  white on brand-600      4.52:1   hover brand-700  6.04:1
        outline  brand-700 on canvas     5.70:1   border brand-600 4.27:1
        inverse  brand-900 on canvas     9.12:1

    The outline border uses -600 rather than the logo -500 for the same
    reason: -500 on canvas is 2.97:1, just under the 3:1 required for a
    non-text UI boundary.

    Usage:
        <x-button href="/portfolio">View the portfolio</x-button>
        <x-button href="/contact" variant="outline">Start a project</x-button>
        <x-button variant="inverse" size="lg" href="/contact">…</x-button>
--}}

@props([
    'href' => null,
    'variant' => 'primary',
    'size' => 'md',
])

@php
    $base = 'inline-flex items-center justify-center rounded-full text-sm tracking-wide transition'
        . ' focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';

    $sizes = [
        'sm' => 'px-5 py-2',
        'md' => 'px-7 py-3',
        'lg' => 'px-8 py-3.5',
    ];

    $variants = [
        'primary' => 'bg-brand-600 text-white hover:bg-brand-700',
        // Same height and radius as primary so a pair sits on one baseline —
        // the border is inset by nothing, the padding matches exactly.
        'outline' => 'border border-brand-600 text-brand-700 hover:bg-brand-600 hover:text-white',
        // For the dark teal CTA band, where the page ground is brand-900.
        'inverse' => 'bg-canvas text-brand-900 hover:bg-white',
    ];

    $classes = $base
        . ' ' . ($sizes[$size] ?? $sizes['md'])
        . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp

@php
    // wire:navigate on internal paths only. Applying it to mailto:, tel:, an
    // in-page #anchor or an off-site URL either does nothing or actively
    // breaks the link, since Livewire would try to fetch it as a page.
    $navigable = is_string($href)
        && str_starts_with($href, '/')
        && ! str_starts_with($href, '//');
@endphp

@if ($href)
    <a href="{{ $href }}" @if ($navigable) wire:navigate @endif {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $attributes->get('type', 'button') }}" {{ $attributes->except('type')->class($classes) }}>{{ $slot }}</button>
@endif
