@php
    $citySuffix = $area ? ' in ' . $area->city : '';
    $isServiceMode = $mode === 'service';
    // First slide image for preloading
    $firstSlide = $renderedSlides[0] ?? null;
@endphp

{{-- Preload the LCP image (first slide) for faster rendering --}}
@if($firstSlide)
@push('head')
<link rel="preload" as="image" href="{{ $firstSlide['image'] }}" fetchpriority="high">
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
        firstSlideWasCached: false,
        showFirstSlideBlur: false,
        autoplay: null,
        isHovered: false,
        isVisible: true,
        isTabVisible: true,
        init() {
            // Mark already-cached images as loaded immediately
            this.slides.forEach((slide, index) => {
                if (window.imageCache?.has(slide.image)) {
                    this.loadedImages.push(index);
                }
            });
            // First slide is always marked loaded (server-rendered)
            if (!this.loadedImages.includes(0)) this.loadedImages.push(0);
            // Check if first slide was browser-cached
            const firstImg = this.$refs.firstSlideImg;
            if (firstImg?.complete && firstImg?.naturalWidth > 0) {
                this.firstSlideWasCached = true;
            } else {
                this.showFirstSlideBlur = true;
            }
            this.startAutoplay();
            document.addEventListener('visibilitychange', () => this.handleTabVisibility());
            // Preload next slides after initial render
            this.$nextTick(() => this.preloadNextSlides());
        },
        preloadNextSlides() {
            // Preload all slide images after first paint
            this.slides.forEach((slide, index) => {
                if (index > 0 && !this.loadedImages.includes(index)) {
                    const img = new Image();
                    img.src = slide.image;
                    img.onload = () => {
                        if (!this.loadedImages.includes(index)) this.loadedImages.push(index);
                        window.imageCache?.set(slide.image, slide.image);
                    };
                }
            });
        },
        isLoaded(index) {
            return this.loadedImages.includes(index);
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
            {{-- Blur placeholder (only shown when full image is loading) --}}
            <img
                x-cloak
                x-show="showFirstSlideBlur && !isLoaded(0)"
                src="{{ $firstSlide['thumb'] }}"
                alt=""
                aria-hidden="true"
                class="absolute inset-0 h-full w-full object-cover blur-xl scale-110 opacity-100"
            />
            {{-- Full-size image with fetchpriority=high for LCP --}}
            <img
                x-ref="firstSlideImg"
                src="{{ $firstSlide['image'] }}"
                alt="{{ $firstSlide['imageAlt'] ?? $firstSlide['alt'] ?? 'Home remodeling project' }}"
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
                {{-- Blur placeholder (only shown when full image is loading) --}}
                <img
                    x-cloak
                    x-show="!window.imageCache?.has(slide.image) && !isLoaded(index)"
                    :src="slide.thumb"
                    alt=""
                    aria-hidden="true"
                    class="absolute inset-0 h-full w-full object-cover blur-xl scale-110 opacity-100"
                />
                {{-- Full-size image (eager for first slide, lazy for others) --}}
                <img
                    :src="slide.image"
                    :alt="slide.imageAlt || slide.heading || slide.alt"
                    :loading="index === 0 ? 'eager' : 'lazy'"
                    :fetchpriority="index === 0 ? 'high' : 'auto'"
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
                    <h2
                        class="font-heading text-4xl font-bold text-white drop-shadow-lg sm:text-5xl lg:text-6xl"
                        x-html="slide.title.replace('\n', '<br>')"
                    ></h2>
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
