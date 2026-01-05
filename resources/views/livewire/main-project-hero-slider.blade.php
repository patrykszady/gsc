<div
    x-data="{
        currentSlide: 0,
        slides: [
            {
                title: 'Kitchens',
                button: 'Show Kitchens',
                link: '/projects/kitchens',
                projectType: 'kitchen',
                image: @js($slideImages['kitchen'])
            },
            {
                title: 'Bathrooms',
                button: 'Show Bathrooms',
                link: '/projects/bathrooms',
                projectType: 'bathroom',
                image: @js($slideImages['bathroom'])
            },
            {
                title: 'Mudrooms\nLaundry Rooms',
                button: 'Show Mudrooms',
                link: '/projects/mudrooms',
                projectType: 'mudroom',
                image: @js($slideImages['mudroom'])
            },
            {
                title: 'Home Remodels',
                button: 'Show Home Remodels',
                link: '/projects/home-remodels',
                projectType: 'home-remodel',
                image: @js($slideImages['home-remodel'])
            }
        ],
        autoplay: null,
        touchStartX: 0,
        touchEndX: 0,
        async refreshCurrentImage() {
            const slide = this.slides[this.currentSlide];
            if (!slide?.projectType) return;
            const url = await $wire.randomHeroImage(slide.projectType);
            if (url) slide.image = url;
        },
        startAutoplay() {
            this.autoplay = setInterval(() => this.next(true), 3000);
        },
        stopAutoplay() {
            clearInterval(this.autoplay);
        },
        next(shouldRefresh = false) {
            this.currentSlide = (this.currentSlide + 1) % this.slides.length;
            if (shouldRefresh) this.refreshCurrentImage();
        },
        prev(shouldRefresh = false) {
            this.currentSlide = (this.currentSlide - 1 + this.slides.length) % this.slides.length;
            if (shouldRefresh) this.refreshCurrentImage();
        },
        goTo(index, shouldRefresh = false) {
            this.currentSlide = index;
            if (shouldRefresh) this.refreshCurrentImage();
        },
        handleTouchStart(e) {
            this.touchStartX = e.changedTouches[0].screenX;
        },
        handleTouchEnd(e) {
            this.touchEndX = e.changedTouches[0].screenX;
            this.handleSwipe();
        },
        handleSwipe() {
            const diff = this.touchStartX - this.touchEndX;
            if (Math.abs(diff) > 50) {
                if (diff > 0) {
                    this.next();
                } else {
                    this.prev();
                }
            }
        }
    }"
    x-init="startAutoplay()"
    @mouseenter="stopAutoplay()"
    @mouseleave="startAutoplay()"
    @keydown.left.window="prev()"
    @keydown.right.window="next()"
    @touchstart="handleTouchStart($event)"
    @touchend="handleTouchEnd($event)"
    class="relative w-full overflow-hidden"
>
    {{-- Slides --}}
    <div class="relative h-[500px] sm:h-[600px] lg:h-[700px]">
        <template x-for="(slide, index) in slides" :key="index">
            <div
                x-show="currentSlide === index"
                x-transition:enter="transition ease-out duration-500"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-300"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0"
            >
                {{-- Background Image --}}
                <img
                    :src="slide.image"
                    :alt="slide.title"
                    class="absolute inset-0 h-full w-full object-cover"
                />

                {{-- Overlay --}}
                <div class="absolute inset-0 bg-black/20"></div>

                {{-- Content --}}
                <div class="relative flex h-full items-end pb-16 sm:pb-20 lg:pb-24">
                    <div class="mx-auto w-full max-w-7xl px-6 lg:px-8">
                        <h2
                            class="font-heading text-4xl font-bold text-white drop-shadow-lg sm:text-5xl lg:text-6xl"
                            x-html="slide.title.replace('\n', '<br>')"
                        ></h2>
                        <div class="mt-6">
                            <a
                                :href="slide.link"
                                class="inline-flex items-center rounded-md bg-sky-500 px-5 py-2.5 text-sm font-semibold text-white shadow-lg transition hover:bg-sky-600"
                                x-text="slide.button"
                            ></a>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Previous Arrow --}}
    <button
        @click="prev()"
        class="absolute left-4 top-1/2 z-10 -translate-y-1/2 p-2 text-white/80 transition hover:text-white"
        aria-label="Previous slide"
    >
        <svg class="size-8 sm:size-10" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
        </svg>
    </button>

    {{-- Next Arrow --}}
    <button
        @click="next()"
        class="absolute right-4 top-1/2 z-10 -translate-y-1/2 p-2 text-white/80 transition hover:text-white"
        aria-label="Next slide"
    >
        <svg class="size-8 sm:size-10" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
        </svg>
    </button>

    {{-- Dot Indicators --}}
    <div class="absolute bottom-6 left-1/2 z-10 flex -translate-x-1/2 gap-3">
        <template x-for="(slide, index) in slides" :key="'dot-' + index">
            <button
                @click="goTo(index)"
                :class="currentSlide === index ? 'bg-white' : 'bg-white/50 hover:bg-white/75'"
                class="size-3 rounded-full transition"
                :aria-label="'Go to slide ' + (index + 1)"
            ></button>
        </template>
    </div>
</div>
