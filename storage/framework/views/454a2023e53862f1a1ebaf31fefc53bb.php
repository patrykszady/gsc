<div
    x-data="{
        currentSlide: 0,
        previousSlide: null,
        backgrounds: <?php echo \Illuminate\Support\Js::from($backgroundImages)->toHtml() ?>,
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
        noBgLoaded: window.imageCache?.has('<?php echo e(asset('images/greg-patryk-no-background.png')); ?>') || false,
        init() {
            document.addEventListener('visibilitychange', () => this.handleTabVisibility());
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
                window.imageCache?.set('<?php echo e(asset('images/greg-patryk.jpg')); ?>', true);
            } else {
                this.showIntroBlur = true;
            }
            // Check no-background image too
            const noBgImg = this.$refs.noBgImg;
            if (noBgImg?.complete && noBgImg?.naturalWidth > 0) {
                this.noBgLoaded = true;
                window.imageCache?.set('<?php echo e(asset('images/greg-patryk-no-background.png')); ?>', true);
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
                }, 5000);
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
        },
        startAutoplay() {
            if (!this.isVisible || !this.isTabVisible || this.introPhase) return;
            this.stopAutoplay();
            this.autoplay = setInterval(() => this.next(), 5000);
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
    
    <div class="relative aspect-[4/3] w-full bg-zinc-200 dark:bg-zinc-700">
        
        <div
            x-show="introPhase"
            x-transition:leave="transition-all duration-500 ease-out"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-110"
            class="absolute inset-0 z-20"
        >
            
            <img
                x-cloak
                x-show="showIntroBlur && !introLoaded"
                x-ref="introThumb"
                src="<?php echo e(asset('images/greg-patryk-thumb.jpg')); ?>"
                alt=""
                aria-hidden="true"
                width="300"
                height="400"
                fetchpriority="high"
                @load="introThumbLoaded = true"
                :class="introThumbLoaded ? 'opacity-100' : 'opacity-0'"
                class="h-full w-full object-cover object-bottom blur-md"
            />
            
            <img
                x-ref="introFull"
                src="<?php echo e(asset('images/greg-patryk.jpg')); ?>"
                alt="Gregory and Patryk - GS Construction"
                width="1200"
                height="1600"
                fetchpriority="high"
                decoding="async"
                @load="introLoaded = true; window.imageCache?.set('<?php echo e(asset('images/greg-patryk.jpg')); ?>', true); startIntroTimer()"
                :class="introWasCached ? 'opacity-100' : (introLoaded ? 'opacity-100 transition-opacity duration-500' : 'opacity-0')"
                class="absolute inset-0 h-full w-full object-cover object-bottom"
            />
        </div>

        
        <template x-for="(bg, index) in backgrounds" :key="index">
            <div
                x-show="!introPhase && (currentSlide === index || previousSlide === index)"
                :style="{ zIndex: currentSlide === index ? 10 : 5 }"
                class="absolute inset-0"
            >
                <img
                    :src="bg.url"
                    alt="Project background"
                    width="2400"
                    height="1350"
                    loading="lazy"
                    decoding="async"
                    class="h-full w-full object-cover"
                    :class="currentSlide === index ? 'opacity-100 transition-opacity duration-500' : 'opacity-100'"
                />
            </div>
        </template>

        
        <div 
            x-cloak
            x-show="!introPhase"
            x-transition:enter="transition-opacity duration-500"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            class="absolute inset-0 z-20 bg-gradient-to-t from-black/20 via-transparent to-black/10"
        ></div>

        
        <div class="absolute inset-x-0 bottom-0 z-30 flex justify-center" wire:ignore>
            <img
                x-ref="noBgImg"
                src="<?php echo e(asset('images/greg-patryk-no-background.png')); ?>"
                alt="Gregory and Patryk - GS Construction"
                width="800"
                height="1000"
                loading="eager"
                decoding="async"
                @load="noBgLoaded = true; window.imageCache?.set('<?php echo e(asset('images/greg-patryk-no-background.png')); ?>', true); tryEndIntro()"
                class="h-auto max-h-full w-auto max-w-full opacity-0 transition-opacity duration-500"
                :class="(!introPhase && noBgLoaded) && 'opacity-100'"
                style="filter: drop-shadow(1px 0 0 white) drop-shadow(-1px 0 0 white) drop-shadow(0 1px 0 white) drop-shadow(0 -1px 0 white) drop-shadow(2px 0 0 white) drop-shadow(-2px 0 0 white) drop-shadow(0 2px 0 white) drop-shadow(0 -2px 0 white);"
            />
        </div>
    </div>

    
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
<?php /**PATH /home/patryk/web/gsc/resources/views/livewire/team-photo-slider.blade.php ENDPATH**/ ?>