@props([
    'images' => [],  // Array of image data: id, url, webpUrl, originalUrl, alt, caption, pageUrl
    'initialIndex' => 0,
    'show' => false, // For standalone usage (not inline lightbox)
])

{{--
    Lightbox Component
    
    Usage with Alpine (inline lightbox):
    <div x-data="{ lightbox: false, currentIndex: 0, images: [...] }">
        <x-lightbox ::show="lightbox" ::images="images" ::current-index="currentIndex" />
    </div>
    
    Or with passed data:
    <x-lightbox :images="$imagesArray" :initial-index="0" />
--}}

<template x-teleport="body">
    <div 
        x-show="lightbox" 
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-2xl overscroll-none"
        x-data="{ isChildZoomed: false }"
        @click.self="!isChildZoomed && close()"
        @keydown.escape.window="isChildZoomed ? null : (lightbox && close())"
        x-effect="if (!lightbox) isChildZoomed = false"
    >
        {{-- Image container using zoomable-image component --}}
        <x-zoomable-image 
            container-class="relative max-w-[90vw] max-h-[85vh] sm:max-w-[80vw] sm:max-h-[80vh] z-20"
            image-class="max-w-[90vw] max-h-[85vh] sm:max-w-[80vw] sm:max-h-[80vh] w-auto h-auto object-contain shadow-2xl"
            rounded="rounded-lg"
            x-on:zoom-changed="isChildZoomed = $event.detail.isZoomed"
        >
            <x-slot:overlays>
                {{-- Caption overlay (bottom of image) --}}
                <div 
                    x-show="current.caption" 
                    class="absolute bottom-0 inset-x-0 bg-linear-to-t from-black/80 via-black/50 to-transparent p-4 pb-10 pt-10 sm:p-6 sm:pb-6 sm:pt-12 rounded-b-lg"
                >
                    <p x-text="current.caption" class="text-white text-sm sm:text-base lg:text-lg text-center line-clamp-2 sm:line-clamp-none"></p>
                </div>

                {{-- Dot indicators (bottom center, over caption) --}}
                <div class="absolute bottom-2 sm:bottom-4 inset-x-0 flex justify-center gap-1.5 sm:gap-2 z-20" x-show="images.length > 1">
                    <template x-for="(img, idx) in images" :key="'dot-' + idx">
                        <button
                            @click.stop="currentIndex = idx"
                            :class="currentIndex === idx ? 'bg-white w-8' : 'bg-white/50 w-3 hover:bg-white/70'"
                            class="h-3 rounded-full transition-all duration-300 shadow-lg"
                            :title="`Go to photo ${idx + 1}`"
                        ></button>
                    </template>
                </div>

                {{-- Links overlay (top left of image) --}}
                <div class="absolute top-4 left-4 flex items-center gap-3 text-sm z-10">
                    <a 
                        :href="current.pageUrl" 
                        @click.stop
                        class="text-white/80 hover:text-white inline-flex items-center gap-1 transition-colors bg-black/50 px-3 py-1.5 rounded-full"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                        Full photo
                    </a>
                    {{-- Hidden but accessible Full Size link --}}
                    <a 
                        :href="current.originalUrl" 
                        target="_blank"
                        @click.stop
                        class="sr-only"
                    >
                        Full size
                    </a>
                </div>
                
                {{-- Counter (top right) --}}
                <div class="absolute top-4 right-4 text-sm z-10" x-show="images.length > 1">
                    <div class="text-white/80 bg-black/50 px-3 py-1.5 rounded-full">
                        <span x-text="currentIndex + 1"></span> / <span x-text="images.length"></span>
                    </div>
                </div>
            </x-slot:overlays>
        </x-zoomable-image>
    </div>
</template>
