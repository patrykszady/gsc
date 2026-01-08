@props([
    'image' => null,
    'size' => 'medium',
    'alt' => null,
    'class' => '',
    'eager' => false,
    'width' => null,
    'height' => null,
])

@if($image)
@php
    $fallbackUrl = $image->getThumbnailUrl($size);
    $webpUrl = $image->getWebpThumbnailUrl($size);
    $altText = $alt ?? $image->seo_alt_text;
    $loading = $eager ? 'eager' : 'lazy';
    $fetchpriority = $eager ? 'high' : 'auto';
@endphp

<picture>
    @if($webpUrl)
        <source srcset="{{ $webpUrl }}" type="image/webp">
    @endif
    <img 
        src="{{ $fallbackUrl }}" 
        alt="{{ $altText }}"
        loading="{{ $loading }}"
        fetchpriority="{{ $fetchpriority }}"
        decoding="async"
        @if($width) width="{{ $width }}" @endif
        @if($height) height="{{ $height }}" @endif
        {{ $attributes->merge(['class' => $class]) }}
    >
</picture>
@endif
