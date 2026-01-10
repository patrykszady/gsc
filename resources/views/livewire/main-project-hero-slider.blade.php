@php
    $citySuffix = $area ? ' in ' . $area->city : '';
    $isServiceMode = $mode === 'service';
    // First slide image for preloading
    $firstSlide = $renderedSlides[0] ?? null;
    // Responsive sizes: use smaller images on smaller screens
    $heroSizes = '(max-width: 640px) 600px, (max-width: 1024px) 1200px, 2400px';
@endphp

{{-- Preload the LCP image (first slide) for faster rendering with responsive srcset --}}
@if($firstSlide)
@push('head')
<link rel="preload" as="image" href="{{ $firstSlide['image'] }}" imagesrcset="{{ $firstSlide['srcset'] ?? '' }}" imagesizes="{{ $heroSizes }}" fetchpriority="high">
@endpush
@endif

<div
    x-data="{
        currentSlide: 0,
        areaCity: @js($area?->city),
        mode: @js($mode),
        projectTypeFilter: @js($projectType),
        slides: @js($renderedSlides),
        loadedImages: [],
        thumbsLoaded: [],
        firstSlideWasCached: false,
        autoplay: null,
        isHovered: false,
        isVisible: true,
        isTabVisible: true,
        nextToLoad: 1,
        init() {
            // Mark already-cached images as loaded immediately
            this.slides.forEach((slide, index) => {
                if (window.imageCache?.has(slide.image)) {
                    this.loadedImages.push(index);
                    this.thumbsLoaded.push(index);
                }
            });
            // Check if first slide was browser-cached
            const firstImg = this.$refs.firstSlideImg;
            if (firstImg?.complete && firstImg?.naturalWidth > 0) {
                this.firstSlideWasCached = true;
                if (!this.loadedImages.includes(0)) this.loadedImages.push(0);
            }
            // Check if first thumb was cached
            const firstThumb = this.$refs.firstSlideThumb;
            if (firstThumb?.complete && firstThumb?.naturalWidth > 0) {
                if (!this.thumbsLoaded.includes(0)) this.thumbsLoaded.push(0);
            }
            this.startAutoplay();
            document.addEventListener('visibilitychange', () => this.handleTabVisibility());
            // Start sequential loading only when visible
            if (this.isVisible) {
                this.loadNextImage();
            }
        },
        loadNextImage() {
            // Only load if visible and there are more images to load
            if (!this.isVisible || this.nextToLoad >= this.slides.length) return;
            
            const index = this.nextToLoad;
            if (this.loadedImages.includes(index)) {
                // Already loaded, move to next
                this.nextToLoad++;
                this.loadNextImage();
                return;
            }
            
            const slide = this.slides[index];
            const img = new Image();
            img.onload = () => {
                if (!this.loadedImages.includes(index)) this.loadedImages.push(index);
                window.imageCache?.set(slide.image, slide.image);
                this.nextToLoad++;
                // Load next image after a small delay to not block main thread
                setTimeout(() => this.loadNextImage(), 100);
            };
            img.onerror = () => {
                this.nextToLoad++;
                this.loadNextImage();
            };
            img.src = slide.image;
        },
        isLoaded(index) {
            return this.loadedImages.includes(index);
        },
        isThumbLoaded(index) {
            return this.thumbsLoaded.includes(index);
        },
        startAutoplay() {
            if (!this.isVisible || !this.isTabVisible || this.isHovered) return;
            this.stopAutoplay();
            this.autoplay = setInterval(() => this.next(), {{ $isServiceMode ? 4000 : 5000 }});
        },
        stopAutoplay() {
            if (this.autoplay) {
                clearInterval(this.autoplay);
                this.autoplay = null;
            }
        },
        next() {
            this.currentSlide = (this.currentSlide + 1) % this.slides.length;
        },
        handleVisibility(isVisible) {
            this.isVisible = isVisible;
            if (isVisible && this.isTabVisible && !this.isHovered) {
                this.startAutoplay();
                // Start loading images when entering viewport
                this.loadNextImage();
            } else {
                this.stopAutoplay();
            }
        },
        handleTabVisibility() {
            this.isTabVisible = !document.hidden;
            if (this.isTabVisible && this.isVisible && !this.isHovered) {
                this.startAutoplay();
            } else {
                this.stopAutoplay();
            }
        }
    }"
    x-intersect:enter.threshold.40="handleVisibility(true)"
    x-intersect:leave.threshold.40="handleVisibility(false)"
    class="relative w-full overflow-hidden"
>
    {{-- Slides --}}
    <div class="relative h-[500px] sm:h-[600px] lg:h-[700px]">
        {{-- Skeleton/shimmer background (shows immediately before anything loads) --}}
        <div 
            x-show="!isLoaded(0) && !isThumbLoaded(0)"
            class="absolute inset-0 bg-gradient-to-br from-zinc-800 via-zinc-700 to-zinc-800 animate-pulse"
        ></div>
        
        {{-- First slide rendered directly in HTML for fastest LCP --}}
        @if($firstSlide)
        <div
            x-show="currentSlide === 0"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="absolute inset-0"
        >
            {{-- Blur placeholder (shows while main image loads) --}}
            <img
                x-ref="firstSlideThumb"
                x-show="!firstSlideWasCached && !isLoaded(0)"
                src="{{ $firstSlide['thumb'] }}"
                alt=""
                aria-hidden="true"
                width="300"
                height="169"
                fetchpriority="low"
                class="absolute inset-0 h-full w-full object-cover blur-xl scale-110"
                :class="isThumbLoaded(0) ? 'opacity-100' : 'opacity-0'"
                @load="thumbsLoaded.includes(0) || thumbsLoaded.push(0)"
            />
            {{-- Full-size image with fetchpriority=high for LCP --}}
            <img
                x-ref="firstSlideImg"
                src="{{ $firstSlide['image'] }}"
                srcset="{{ $firstSlide['srcset'] ?? '' }}"
                sizes="{{ $heroSizes }}"
                alt="{{ $firstSlide['imageAlt'] ?? $firstSlide['alt'] ?? 'Home remodeling project' }}"
                width="2400"
                height="1350"
                fetchpriority="high"
                decoding="async"
                class="absolute inset-0 h-full w-full object-cover"
                :class="firstSlideWasCached ? 'opacity-100' : (isLoaded(0) ? 'opacity-100 transition-opacity duration-500' : 'opacity-0')"
                @load="loadedImages.includes(0) || loadedImages.push(0)"
            />
            {{-- Overlay --}}
            <div class="absolute inset-0 bg-black/20"></div>
        </div>
        @endif

        {{-- Remaining slides rendered via Alpine template --}}
        <template x-for="(slide, index) in slides" :key="index">
            <div
                x-show="currentSlide === index && index > 0"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0"
            >
                {{-- Skeleton for slides that haven't started loading --}}
                <div 
                    x-show="!isLoaded(index) && !isThumbLoaded(index)"
                    class="absolute inset-0 bg-gradient-to-br from-zinc-800 via-zinc-700 to-zinc-800 animate-pulse"
                ></div>
                {{-- Blur placeholder (shows while main image loads) --}}
                <img
                    x-show="!window.imageCache?.has(slide.image) && !isLoaded(index)"
                    :src="slide.thumb"
                    alt=""
                    aria-hidden="true"
                    width="300"
                    height="169"
                    class="absolute inset-0 h-full w-full object-cover blur-xl scale-110"
                    :class="isThumbLoaded(index) ? 'opacity-100' : 'opacity-0'"
                    @load="thumbsLoaded.includes(index) || thumbsLoaded.push(index)"
                />
                {{-- Full-size image --}}
                <img
                    :src="slide.image"
                    :srcset="slide.srcset || ''"
                    sizes="{{ $heroSizes }}"
                    :alt="slide.imageAlt || slide.heading || slide.alt"
                    width="2400"
                    height="1350"
                    loading="lazy"
                    fetchpriority="auto"
                    decoding="async"
                    class="absolute inset-0 h-full w-full object-cover"
                    :class="window.imageCache?.has(slide.image) ? 'opacity-100' : (isLoaded(index) ? 'opacity-100 transition-opacity duration-500' : 'opacity-0')"
                    @load="loadedImages.includes(index) || loadedImages.push(index)"
                />
                {{-- Overlay --}}
                <div class="absolute inset-0 bg-black/20"></div>
            </div>
        </template>

        @if($isServiceMode)
        {{-- Service Page Content (per-slide) --}}
        <template x-for="(slide, index) in slides" :key="'content-' + index">
            <div
                x-show="currentSlide === index"
                x-transition:enter="transition-opacity ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-100"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 flex items-end pb-16 sm:pb-20 lg:pb-24"
            >
                <div 
                    class="mx-auto w-full max-w-7xl px-6 lg:px-8"
                    @mouseenter="isHovered = true; stopAutoplay()"
                    @mouseleave="isHovered = false; startAutoplay()"
                >
                    <div class="lg:max-w-[50%]">
                        @if($label)
                        <span x-show="index === 0" class="inline-flex items-center rounded-full bg-sky-500 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-white shadow-lg">
                            {{ $label }}
                        </span>
                        @endif
                        <h1 
                            class="mt-3 font-heading text-4xl font-bold text-white drop-shadow-lg sm:text-5xl lg:text-6xl"
                            x-text="slide.heading"
                        ></h1>
                        <p 
                            x-show="slide.subheading"
                            x-text="slide.subheading"
                            class="mt-4 text-lg text-white drop-shadow-lg sm:text-xl"
                        ></p>
                        <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                            @if($primaryCtaUrl && $primaryCtaText)
                            <x-buttons.cta href="{{ $primaryCtaUrl }}" size="lg">
                                {{ $primaryCtaText }}
                            </x-buttons.cta>
                            @endif
                            @if($secondaryCtaUrl && $secondaryCtaText)
                            <x-buttons.cta href="{{ $secondaryCtaUrl }}" variant="secondary" size="lg" :onDark="true">
                                {{ $secondaryCtaText }}
                            </x-buttons.cta>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </template>
        @else
        {{-- Home Page Content (per-slide) --}}
        <template x-for="(slide, index) in slides" :key="'content-' + index">
            <div
                x-show="currentSlide === index"
                x-transition:enter="transition-opacity ease-out duration-150"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity ease-in duration-100"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 flex items-end pb-16 sm:pb-20 lg:pb-24"
            >
                <div 
                    class="mx-auto w-full max-w-7xl px-6 lg:px-8"
                    @mouseenter="isHovered = true; stopAutoplay()"
                    @mouseleave="isHovered = false; startAutoplay()"
                >
                    <h1
                        class="font-heading text-4xl font-bold text-white drop-shadow-lg sm:text-5xl lg:text-6xl"
                        x-html="slide.title.replace('\n', '<br>')"
                    ></h1>
                    <p 
                        x-show="areaCity" 
                        x-text="'in ' + areaCity"
                        class="mt-2 text-xl font-medium text-white drop-shadow-lg sm:text-2xl"
                    ></p>
                    <div class="mt-8 flex flex-wrap items-center gap-x-6 gap-y-4">
                        <a
                            :href="slide.link"
                            class="inline-flex items-center justify-center rounded-lg bg-sky-500 px-6 py-3 text-base font-semibold uppercase tracking-wide text-white shadow-lg transition hover:bg-sky-600"
                            x-text="slide.button"
                        ></a>
                        @if($secondaryCtaUrl && $secondaryCtaText)
                        <x-buttons.cta href="{{ $secondaryCtaUrl }}" variant="secondary" size="lg" :onDark="true">
                            {{ $secondaryCtaText }}
                        </x-buttons.cta>
                        @endif
                    </div>
                </div>
            </div>
        </template>
        @endif
    </div>

    {{-- Dot Indicators (display only) --}}
    <div class="absolute bottom-6 left-1/2 z-10 flex -translate-x-1/2 gap-2">
        <template x-for="(slide, index) in slides" :key="'dot-' + index">
            <div
                :class="currentSlide === index ? 'bg-white w-8' : 'bg-white/50 w-3'"
                class="h-3 rounded-full transition-all duration-300"
            ></div>
        </template>
    </div>
</div>
