@props([
    'href' => null,
    'variant' => 'primary', // primary, secondary
    'size' => 'md', // sm, md, lg
    'onDark' => false, // force light text for dark backgrounds (like hero sliders)
])

@php
    $baseClasses = 'inline-flex items-center justify-center rounded-lg font-semibold tracking-wide transition';
    
    $sizes = [
        'sm' => 'px-4 py-2 text-sm',
        'md' => 'px-5 py-2.5 text-sm',
        'lg' => 'px-6 py-3 text-base',
    ];
    
    $variants = [
        'primary' => 'uppercase bg-sky-500 text-white shadow-lg hover:bg-sky-600',
        'secondary' => $onDark 
            ? 'capitalize border border-transparent text-white hover:border-white'
            : 'capitalize border border-transparent text-zinc-700 hover:border-zinc-400 dark:text-white dark:hover:border-white',
        'white' => 'uppercase bg-white text-sky-600 shadow-lg hover:bg-sky-50 hover:shadow-xl',
        'white-secondary' => 'capitalize border border-transparent text-white hover:border-white',
    ];
    
    $sizeClasses = $sizes[$size] ?? $sizes['md'];
    $variantClasses = $variants[$variant] ?? $variants['primary'];
    
    // Auto-detect tracking location from current route
    $trackLocation = request()->route()?->getName() ?? Str::slug(request()->path());
@endphp

<a 
    @if($href) href="{{ $href }}" wire:navigate @endif
    @click="trackCTA($el.textContent.trim(), '{{ $trackLocation }}')"
    {{ $attributes->merge(['class' => $baseClasses . ' ' . $sizeClasses . ' ' . $variantClasses]) }}
>
    {{ $slot }}
    @if($variant === 'secondary')
    <span class="ml-2">&rarr;</span>
    @endif
</a>
