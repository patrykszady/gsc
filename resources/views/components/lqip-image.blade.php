@props([
    'image' => null,           // ProjectImage model
    'src' => null,             // Direct URL (if no model)
    'thumb' => null,           // Direct thumbnail URL (if no model)
    'size' => 'medium',        // Thumbnail size for ProjectImage
    'alt' => '',
    'class' => '',
    'eager' => false,          // fetchpriority="high" and no lazy loading
    'aspectRatio' => null,     // e.g., '4/3', '16/9', 'square'
    'width' => null,
    'height' => null,
    'rounded' => null,         // e.g., 'lg', '2xl', 'full'
    'objectFit' => 'cover',    // cover, contain, fill, etc.
])

@php
    // Determine URLs from either ProjectImage model or direct props
    if ($image) {
        $fullUrl = $image->getWebpThumbnailUrl($size) ?? $image->getThumbnailUrl($size);
        $thumbUrl = $image->getWebpThumbnailUrl('thumb') ?? $image->getThumbnailUrl('thumb') ?? $fullUrl;
        $altText = $alt ?: $image->seo_alt_text;
    } else {
        $fullUrl = $src;
        $thumbUrl = $thumb ?? $src;
        $altText = $alt;
    }
    
    // Build aspect ratio class
    $aspectClass = match($aspectRatio) {
        'square' => 'aspect-square',
        '4/3' => 'aspect-[4/3]',
        '3/4' => 'aspect-[3/4]',
        '16/9' => 'aspect-video',
        '3/2' => 'aspect-[3/2]',
        '2/3' => 'aspect-[2/3]',
        default => $aspectRatio ? "aspect-[$aspectRatio]" : '',
    };
    
    // Build rounded class
    $roundedClass = $rounded ? "rounded-$rounded" : '';
    
    // Object fit class
    $objectClass = "object-$objectFit";
    
    // Loading strategy
    $loading = $eager ? 'eager' : 'lazy';
    $fetchpriority = $eager ? 'high' : 'auto';
    
    // Unique ID for this image instance
    $imageId = 'lqip-' . md5($fullUrl . microtime());
@endphp

@if($fullUrl)
<div 
    x-data="{
        loaded: false,
        thumbLoaded: false,
        wasCached: false,
        showBlur: false,
        init() {
            // Check if browser already has the image cached (complete)
            const fullImg = this.$refs.fullImg;
            if (fullImg?.complete && fullImg?.naturalWidth > 0) {
                this.wasCached = true;
                this.loaded = true;
            } else {
                this.showBlur = true;
            }
        },
        onLoad() {
            this.loaded = true;
            window.imageCache?.set('{{ $fullUrl }}', '{{ $fullUrl }}');
        }
    }"
    {{ $attributes->merge(['class' => "relative overflow-hidden bg-zinc-200 dark:bg-zinc-700 $aspectClass $roundedClass $class"]) }}
>
    {{-- Blur placeholder (only shown when full image is loading) --}}
    <img
        x-cloak
        x-ref="thumbImg"
        x-show="showBlur && !loaded"
        src="{{ $thumbUrl }}"
        alt=""
        aria-hidden="true"
        class="absolute inset-0 h-full w-full {{ $objectClass }} blur-xl scale-110"
        :class="thumbLoaded ? 'opacity-100' : 'opacity-0'"
        @load="thumbLoaded = true"
    />
    
    {{-- Full-size image --}}
    <img
        x-ref="fullImg"
        src="{{ $fullUrl }}"
        alt="{{ $altText }}"
        loading="{{ $loading }}"
        fetchpriority="{{ $fetchpriority }}"
        decoding="async"
        @if($width) width="{{ $width }}" @endif
        @if($height) height="{{ $height }}" @endif
        class="absolute inset-0 h-full w-full {{ $objectClass }}"
        :class="wasCached ? 'opacity-100' : (loaded ? 'opacity-100 transition-opacity duration-300' : 'opacity-0')"
        @load="onLoad()"
    />
</div>
@endif
