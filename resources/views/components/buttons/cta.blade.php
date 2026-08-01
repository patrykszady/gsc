@props([
    'href' => null,
    'variant' => 'primary', // primary, secondary
    'size' => 'md', // sm, md, lg
    'onDark' => false, // force light text for dark backgrounds (like hero sliders)
    // 'button' for an action rather than a link (wire:click, form submit).
    // Explicit rather than inferred from a missing $href, because the hero
    // slider binds its href through Alpine (::href) and must stay an <a>.
    'as' => 'a',
])

@php
    $baseClasses = 'inline-flex items-center justify-center rounded-lg font-semibold tracking-wide transition';
    
    $sizes = [
        'sm' => 'px-4 py-2 text-sm',
        'md' => 'px-5 py-2.5 text-sm',
        'lg' => 'px-6 py-3 text-base',
    ];
    
    // Every non-solid variant carries a VISIBLE border at rest and a stronger
    // one plus a background wash on hover. They used to sit on
    // border-transparent, which meant a button that looked like plain text
    // until you happened to point at it — the secondary CTA beside a solid one
    // read as a link, not a button. 'secondary' and 'outline' differ only in
    // letter casing now; the border treatment is deliberately identical so the
    // two never drift apart again.
    $variants = [
        'primary' => 'uppercase bg-sky-600 text-white shadow-lg hover:bg-sky-700',
        'secondary' => $onDark
            ? 'capitalize border border-white/60 text-white hover:border-white hover:bg-white/10'
            : 'capitalize border border-zinc-300 text-zinc-700 hover:border-zinc-500 hover:bg-zinc-50 dark:border-white/30 dark:text-white dark:hover:border-white dark:hover:bg-white/10',
        'outline' => 'uppercase border border-zinc-300 text-zinc-700 hover:border-zinc-500 hover:bg-zinc-50 dark:border-white/30 dark:text-white dark:hover:border-white dark:hover:bg-white/10',
        'white' => 'uppercase bg-white text-sky-600 shadow-lg hover:bg-sky-50 hover:shadow-xl',
        'white-secondary' => 'capitalize border border-white/60 text-white hover:border-white hover:bg-white/10',
    ];
    
    $sizeClasses = $sizes[$size] ?? $sizes['md'];
    $variantClasses = $variants[$variant] ?? $variants['primary'];
    
    // Auto-detect tracking location from current route
    $trackLocation = request()->route()?->getName() ?? Str::slug(request()->path());
@endphp

@php $tag = $as === 'button' ? 'button' : 'a'; @endphp

<{{ $tag }}
    @if($tag === 'button') type="{{ $attributes->get('type', 'button') }}" @endif
    @if($href) href="{{ $href }}" wire:navigate @endif
    @click="trackCTA($el.textContent.trim(), '{{ $trackLocation }}')"
    {{ $attributes->except('type')->merge(['class' => $baseClasses . ' ' . $sizeClasses . ' ' . $variantClasses]) }}
>
    {{ $slot }}
    @if($variant === 'secondary')
    <span class="ml-2">&rarr;</span>
    @endif
</{{ $tag }}>
