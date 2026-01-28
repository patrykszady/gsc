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
        // Use the smallest thumbnail (thumb = 150x150) for progressive blur placeholder
        $thumbUrl = $image->getWebpThumbnailUrl('thumb') ?? $image->getThumbnailUrl('thumb') ?? $fullUrl;
        // Fallback alt text: use seo_alt_text, then generate from project if available
        $altText = $alt 
            ?: $image->seo_alt_text 
            ?: ($image->project ? ucfirst(str_replace('-', ' ', $image->project->project_type)) . ' remodeling by GS Construction' : 'GS Construction remodeling project');
    } else {
        $fullUrl = $src;
        $thumbUrl = $thumb ?? $src;
        $altText = $alt ?: 'GS Construction remodeling';
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
    $fetchpriority = $eager ? 'high' : 'low';
@endphp

@if($fullUrl)
{{-- 
    Progressive blur image component:
    1. Shows blurred thumbnail immediately (visible by default via CSS)
    2. Loads full image in background (hidden by default via CSS)
    3. When full image loads, fades it in over the blur
    4. Caches in window.imageCache for cross-page reuse
--}}
<div 
    x-data="{
        loaded: false,
        cached: false,
        init() {
            // Check global cache first - if cached, show immediately without blur
            if (window.imageCache?.has('{{ $fullUrl }}')) {
                this.cached = true;
                this.loaded = true;
                return;
            }
            
            // Check if browser already has the image cached (complete)
            // Use $nextTick to ensure refs are available
            this.$nextTick(() => {
                const fullImg = this.$refs.fullImg;
                if (fullImg?.complete && fullImg?.naturalWidth > 0) {
                    this.cached = true;
                    this.loaded = true;
                    window.imageCache?.set('{{ $fullUrl }}', '{{ $fullUrl }}');
                }
            });
        },
        onLoad() {
            this.loaded = true;
            window.imageCache?.set('{{ $fullUrl }}', '{{ $fullUrl }}');
        }
    }"
    x-init="$nextTick(() => { if ($refs.fullImg?.complete && $refs.fullImg?.naturalWidth > 0) { loaded = true; cached = true; } })"
    {{ $attributes->merge(['class' => "relative overflow-hidden bg-zinc-200 dark:bg-zinc-700 $aspectClass $roundedClass $class"]) }}
>
    {{-- Progressive blur placeholder (smallest thumbnail, heavily blurred) --}}
    {{-- Visible by default, hidden after full image loads --}}
    <img
        src="{{ $thumbUrl }}"
        alt=""
        aria-hidden="true"
        loading="eager"
        decoding="async"
        class="absolute inset-0 h-full w-full {{ $objectClass }} blur-lg scale-105 transition-opacity duration-500"
        :class="(loaded || cached) ? 'opacity-0' : 'opacity-100'"
    />
    
    {{-- Full-size image - hidden by default, fades in over blur when loaded --}}
    <img
        x-ref="fullImg"
        src="{{ $fullUrl }}"
        alt="{{ $altText }}"
        loading="{{ $loading }}"
        fetchpriority="{{ $fetchpriority }}"
        decoding="async"
        @if($width) width="{{ $width }}" @endif
        @if($height) height="{{ $height }}" @endif
        style="opacity: 0;"
        class="absolute inset-0 h-full w-full {{ $objectClass }} transition-opacity duration-500"
        :style="(loaded || cached) ? 'opacity: 1' : 'opacity: 0'"
        @load="onLoad()"
        onload="this.parentElement.__x && (this.parentElement.__x.$data.loaded = true)"
    />
</div>
@endif
