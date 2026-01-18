<div
    x-data="{
        currentSlide: 0,
        previousSlide: null,
        backgrounds: @js($backgroundImages),
        bgLoaded: [],
        nextBgToLoad: 0,
        autoplay: null,
        isVisible: false,
        isTabVisible: true,
        introPhase: true,
        introLoaded: false,
        introWasCached: false,
        showIntroBlur: false,
        introThumbLoaded: false,
        introTimer: null,
        introTimerDone: false,
        noBgLoaded: window.imageCache?.has('{{ asset('images/greg-patryk-no-background.webp') }}') || false,
        init() {
            document.addEventListener('visibilitychange', () => this.handleTabVisibility());
            // Mark cached background images as loaded
            this.backgrounds.forEach((bg, index) => {
                if (window.imageCache?.has(bg.url)) {
                    this.bgLoaded.push(index);
                }
            });
            // Check if element is already in viewport (intersection may have fired before init)
            const rect = this.$el.getBoundingClientRect();
            const inViewport = rect.top < window.innerHeight && rect.bottom > 0;
            if (inViewport) {
                this.isVisible = true;
            }
            // Check if intro image is already loaded (browser cached)
            const full = this.$refs.introFull;
            if (full?.complete && full?.naturalWidth > 0) {
                this.introLoaded = true;
                this.introWasCached = true;
                window.imageCache?.set('{{ asset('images/greg-patryk.webp') }}', true);
            } else {
                this.showIntroBlur = true;
            }
            // Check no-background image too
            const noBgImg = this.$refs.noBgImg;
            if (noBgImg?.complete && noBgImg?.naturalWidth > 0) {
                this.noBgLoaded = true;
                window.imageCache?.set('{{ asset('images/greg-patryk-no-background.webp') }}', true);
            }
            // Start intro timer if conditions are met
            if (this.introLoaded && this.isVisible && this.isTabVisible) {
                this.startIntroTimer();
            }
            // If both conditions already met (cached), try to end intro immediately
            if (this.introTimerDone && this.noBgLoaded) {
                this.tryEndIntro();
            }
        },
        startIntroTimer() {
            if (this.isVisible && this.isTabVisible && !this.introTimer && this.introPhase) {
                this.introTimer = setTimeout(() => {
                    this.introTimerDone = true;
                    this.tryEndIntro();
                }, 2000);
            }
        },
        tryEndIntro() {
            // Only end intro when BOTH timer is done AND no-background image is loaded
            if (this.introTimerDone && this.noBgLoaded && this.introPhase) {
                this.endIntro();
            }
        },
        endIntro() {
            this.introPhase = false;
            this.startAutoplay();
            // Start loading background images when intro ends
            this.loadNextBackground();
        },
        loadNextBackground() {
            // Only load if visible and there are more backgrounds to load
            if (!this.isVisible || this.nextBgToLoad >= this.backgrounds.length) return;
            
            const index = this.nextBgToLoad;
            if (this.bgLoaded.includes(index)) {
                this.nextBgToLoad++;
                this.loadNextBackground();
                return;
            }
            
            const bg = this.backgrounds[index];
            const img = new Image();
            img.onload = () => {
                if (!this.bgLoaded.includes(index)) this.bgLoaded.push(index);
                window.imageCache?.set(bg.url, bg.url);
                this.nextBgToLoad++;
                // Load next after a small delay
                setTimeout(() => this.loadNextBackground(), 100);
            };
            img.onerror = () => {
                this.nextBgToLoad++;
                this.loadNextBackground();
            };
            img.src = bg.url;
        },
        isBgLoaded(index) {
            return this.bgLoaded.includes(index);
        },
        startAutoplay() {
            if (!this.isVisible || !this.isTabVisible || this.introPhase) return;
            this.stopAutoplay();
            this.autoplay = setInterval(() => this.next(), 3000);
        },
        stopAutoplay() {
            if (this.autoplay) {
                clearInterval(this.autoplay);
                this.autoplay = null;
            }
            if (this.introTimer) {
                clearTimeout(this.introTimer);
                this.introTimer = null;
            }
        },
        handleVisibility(visible) {
            this.isVisible = visible;
            if (visible && this.isTabVisible) {
                if (this.introPhase && this.introLoaded) {
                    this.startIntroTimer();
                } else if (!this.introPhase) {
                    this.startAutoplay();
                    this.loadNextBackground();
                }
            } else {
                this.stopAutoplay();
            }
        },
        handleTabVisibility() {
            this.isTabVisible = !document.hidden;
            if (this.isTabVisible && this.isVisible) {
                if (this.introPhase && this.introLoaded) {
                    this.startIntroTimer();
                } else if (!this.introPhase) {
                    this.startAutoplay();
                }
            } else {
                this.stopAutoplay();
            }
        },
        async next() {
            this.previousSlide = this.currentSlide;
            this.currentSlide = (this.currentSlide + 1) % this.backgrounds.length;
            
            // Clear previousSlide after transition completes
            setTimeout(() => {
                const oldPrev = this.previousSlide;
                this.previousSlide = null;
                // Refresh the image that just went out of view
                $wire.refreshBackgroundImage(oldPrev).then(newUrl => {
                    if (newUrl) {
                        this.backgrounds[oldPrev].url = newUrl;
                    }
                });
            }, 550);
        }
    }"
    x-intersect:enter.threshold.40="handleVisibility(true)"
    x-intersect:leave.threshold.40="handleVisibility(false)"
    class="relative w-full overflow-hidden rounded-xl shadow-xl ring-1 ring-zinc-200 dark:ring-zinc-800"
>
    {{-- Background Images (rotating) --}}
    <div class="relative aspect-[4/3] w-full bg-zinc-200 dark:bg-zinc-700">
        {{-- Intro Phase: greg-patryk with LQIP --}}
        <div
            x-show="introPhase"
            x-transition:leave="transition-all duration-500 ease-out"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-110"
            class="absolute inset-0 z-20"
        >
            {{-- Blur placeholder (only shown when full image is loading) --}}
            <picture x-cloak x-show="showIntroBlur && !introLoaded">
                <source srcset="{{ asset('images/greg-patryk-thumb.webp') }}" type="image/webp">
                <img
                    x-ref="introThumb"
                    src="{{ asset('images/greg-patryk-thumb.jpg') }}"
                    alt=""
                    aria-hidden="true"
                    width="300"
                    height="400"
                    fetchpriority="high"
                    @load="introThumbLoaded = true"
                    :class="introThumbLoaded ? 'opacity-100' : 'opacity-0'"
                    class="h-full w-full object-cover object-bottom blur-md"
                />
            </picture>
            {{-- Full intro image (layers on top when loaded) --}}
            <picture>
                <source srcset="{{ asset('images/greg-patryk.webp') }}" type="image/webp">
                <img
                    x-ref="introFull"
                    src="{{ asset('images/greg-patryk.jpg') }}"
                    alt="Gregory and Patryk - GS Construction"
                    width="1200"
                    height="1600"
                    fetchpriority="high"
                    decoding="async"
                    @load="introLoaded = true; window.imageCache?.set('{{ asset('images/greg-patryk.webp') }}', true); startIntroTimer()"
                    :class="introWasCached ? 'opacity-100' : (introLoaded ? 'opacity-100 transition-opacity duration-500' : 'opacity-0')"
                    class="absolute inset-0 h-full w-full object-cover object-bottom"
                />
            </picture>
        </div>

        {{-- Sliding Phase: Background images - current fades in over previous --}}
        <template x-for="(bg, index) in backgrounds" :key="index">
            <div
                x-show="!introPhase && (currentSlide === index || previousSlide === index)"
                :style="{ zIndex: currentSlide === index ? 10 : 5 }"
                class="absolute inset-0"
            >
                {{-- Skeleton while loading --}}
                <div 
                    x-show="!isBgLoaded(index)"
                    class="absolute inset-0 bg-gradient-to-br from-zinc-300 via-zinc-200 to-zinc-300 dark:from-zinc-700 dark:via-zinc-600 dark:to-zinc-700 animate-pulse"
                ></div>
                {{-- Only render img when loaded or about to be displayed --}}
                <img
                    x-show="isBgLoaded(index)"
                    :src="isBgLoaded(index) ? bg.url : ''"
                    alt="Project background"
                    width="2400"
                    height="1350"
                    decoding="async"
                    class="h-full w-full object-cover"
                    :class="currentSlide === index ? 'opacity-100 transition-opacity duration-500' : 'opacity-100'"
                    @load="bgLoaded.includes(index) || bgLoaded.push(index)"
                />
            </div>
        </template>

        {{-- Subtle overlay for better foreground visibility (sliding phase only) --}}
        <div 
            x-cloak
            x-show="!introPhase"
            x-transition:enter="transition-opacity duration-500"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="absolute inset-0 z-20 bg-gradient-to-t from-black/20 via-transparent to-black/10"
        ></div>

        {{-- Foreground: Greg & Patryk no-background (always loads, visible in sliding phase) --}}
        <div class="absolute inset-x-0 bottom-0 z-30 flex justify-center" wire:ignore>
            <picture>
                {{-- Small WebP for mobile (under 640px) --}}
                <source 
                    media="(max-width: 639px)" 
                    srcset="{{ asset('images/greg-patryk-no-background-small.webp') }}" 
                    type="image/webp"
                >
                {{-- Full WebP for desktop --}}
                <source 
                    srcset="{{ asset('images/greg-patryk-no-background.webp') }}" 
                    type="image/webp"
                >
                {{-- PNG fallback --}}
                <img
                    x-ref="noBgImg"
                    src="{{ asset('images/greg-patryk-no-background.png') }}"
                    alt="Gregory and Patryk - GS Construction"
                    width="800"
                    height="1000"
                    loading="eager"
                    decoding="async"
                    @load="noBgLoaded = true; window.imageCache?.set('{{ asset('images/greg-patryk-no-background.webp') }}', true); tryEndIntro()"
                    class="h-auto max-h-full w-auto max-w-full opacity-0 transition-opacity duration-500"
                    :class="(!introPhase && noBgLoaded) && 'opacity-100'"
                    style="filter: drop-shadow(1px 0 0 white) drop-shadow(-1px 0 0 white) drop-shadow(0 1px 0 white) drop-shadow(0 -1px 0 white) drop-shadow(2px 0 0 white) drop-shadow(-2px 0 0 white) drop-shadow(0 2px 0 white) drop-shadow(0 -2px 0 white);"
                />
            </picture>
        </div>
    </div>

    {{-- Dot Indicators (sliding phase only) --}}
    <div 
        x-cloak
        x-show="!introPhase"
        x-transition:enter="transition-opacity duration-500"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 gap-2"
    >
        <template x-for="(bg, index) in backgrounds" :key="'dot-' + index">
            <button
                @click="currentSlide = index"
                :class="currentSlide === index ? 'bg-white' : 'bg-white/50 hover:bg-white/75'"
                class="size-2 rounded-full transition"
                :aria-label="'Go to slide ' + (index + 1)"
            ></button>
        </template>
    </div>
</div>
