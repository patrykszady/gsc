<div
    x-data="{
        currentSlide: 0,
        backgrounds: <?php echo \Illuminate\Support\Js::from($backgroundImages)->toHtml() ?>,
        autoplay: null,
        isVisible: false,
        startAutoplay() {
            if (!this.isVisible) return;
            this.stopAutoplay();
            this.autoplay = setInterval(() => this.next(), 5000);
        },
        stopAutoplay() {
            if (this.autoplay) {
                clearInterval(this.autoplay);
                this.autoplay = null;
            }
        },
        handleVisibility(visible) {
            this.isVisible = visible;
            if (visible) {
                this.startAutoplay();
            } else {
                this.stopAutoplay();
            }
        },
        async next() {
            const prevSlide = this.currentSlide;
            this.currentSlide = (this.currentSlide + 1) % this.backgrounds.length;
            
            // Refresh the image that just went out of view
            const newUrl = await $wire.refreshBackgroundImage(prevSlide);
            if (newUrl) {
                this.backgrounds[prevSlide].url = newUrl;
            }
        }
    }"
    x-intersect:enter.full="handleVisibility(true)"
    x-intersect:leave.full="handleVisibility(false)"
    class="relative w-full overflow-hidden rounded-xl shadow-xl ring-1 ring-zinc-200 dark:ring-zinc-800"
>
    
    <div class="relative aspect-[4/3] w-full">
        <template x-for="(bg, index) in backgrounds" :key="index">
            <div
                x-show="currentSlide === index"
                x-transition:enter="transition-opacity duration-500 ease-in-out"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition-opacity duration-500 ease-in-out"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                x-bind:class="{ 'transition-none': !isVisible }"
                class="absolute inset-0"
            >
                <img
                    :src="bg.url"
                    alt="Project background"
                    class="h-full w-full object-cover"
                />
            </div>
        </template>

        
        <div class="absolute inset-0 bg-gradient-to-t from-black/20 via-transparent to-black/10"></div>

        
        <div class="absolute inset-x-0 bottom-0 flex justify-center">
            <img
                src="<?php echo e(asset('images/greg-patryk-no-background.png')); ?>"
                alt="Gregory and Patryk - GS Construction"
                class="h-auto max-h-full w-auto max-w-full"
                style="filter: drop-shadow(0 0 0 white) drop-shadow(1px 0 0 white) drop-shadow(-1px 0 0 white) drop-shadow(0 1px 0 white) drop-shadow(0 -1px 0 white) drop-shadow(2px 0 0 white) drop-shadow(-2px 0 0 white) drop-shadow(0 2px 0 white) drop-shadow(0 -2px 0 white) drop-shadow(1px 1px 0 white) drop-shadow(-1px -1px 0 white) drop-shadow(1px -1px 0 white) drop-shadow(-1px 1px 0 white);"
            />
        </div>
    </div>

    
    <div class="absolute bottom-3 left-1/2 z-10 flex -translate-x-1/2 gap-2">
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