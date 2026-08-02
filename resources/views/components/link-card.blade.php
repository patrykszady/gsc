{{--
    The bordered index-card link — the tile used by every "index of guides"
    page (/costs, /permits, /insurance-claims, /compare, /trades,
    /design-partners).

    Its ~150-char class string was pasted at six call sites and had already
    forked: design-partners hovered zinc while everyone else hovered sky.
    One component, one hover.

    Renders an <a> when href is given (wire:navigate on internal paths, new
    tab + noopener on external), otherwise a plain <div> for a non-linked tile.
    Content comes through the slot — the pages' card bodies differ, only the
    chrome is shared.
--}}

@props([
    'href' => null,
])

@php
    $chrome = 'group flex h-full flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500';
    $external = $href && ! str_starts_with($href, url('/')) && preg_match('#^https?://#', $href);
@endphp

@if($href)
    <a
        href="{{ $href }}"
        @if($external) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
        {{ $attributes->class($chrome) }}
    >
        {{ $slot }}
    </a>
@else
    <div {{ $attributes->class($chrome) }}>
        {{ $slot }}
    </div>
@endif
